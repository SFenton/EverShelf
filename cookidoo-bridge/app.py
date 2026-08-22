#!/usr/bin/env python3
"""Authenticated, metadata-only Cookidoo bridge."""

from __future__ import annotations

import asyncio
from collections import deque
from collections.abc import Awaitable, Callable, Mapping, Sequence
from dataclasses import dataclass, field
from datetime import date
import hmac
import json
import logging
import math
import os
from pathlib import Path
import re
import stat
import time
from types import SimpleNamespace
from typing import Any
import unicodedata
from urllib.parse import urlparse
import zlib

import aiohttp
from aiohttp import web
from cookidoo_api import Cookidoo
from cookidoo_api.const import COMMUNITY_PROFILE_PATH, RECIPE_PATH
from cookidoo_api.exceptions import (
    CookidooAuthException,
    CookidooConfigException,
    CookidooException,
    CookidooParseException,
    CookidooRequestException,
    CookidooResponseException,
)
from cookidoo_api.helpers import (
    cookidoo_search_result_from_json,
    get_localization_options,
)
from cookidoo_api.types import CookidooConfig, CookidooLocalizationConfig
from yarl import URL

LOGGER = logging.getLogger("cookidoo_bridge")
logging.getLogger("cookidoo_api").setLevel(logging.WARNING)

BUILD_REVISION_PATH = Path(__file__).with_name(".build-revision")


def _build_revision() -> str:
    try:
        revision = BUILD_REVISION_PATH.read_text(encoding="ascii").strip()
    except OSError:
        return "unknown"
    return revision if re.fullmatch(r"[0-9a-f]{40}(?:[0-9a-f]{24})?", revision) else "unknown"


BUILD_REVISION = _build_revision()

MAX_BODY_BYTES = 2 * 1024 * 1024
MAX_RESPONSE_BYTES = 1_000_000
MAX_UPSTREAM_RESPONSE_BYTES = 2_000_000
MAX_RESPONSE_RECIPES = 20
MAX_INGREDIENT_FILTERS = 25
MAX_RECIPE_INGREDIENTS = 200
MAX_RECIPE_INGREDIENT_GROUPS = 40
MAX_RECIPE_DESCRIPTIVE_ASSETS = 100
MAX_GROUP_TITLE_TEXT = 160
MAX_PROVIDER_REFERENCE_TEXT = 200
MAX_DEFAULT_TITLE_TEXT = 200
MAX_RECIPE_EQUIPMENT = 50
MAX_RECIPE_DEVICES = 50
MAX_DEVICE_TEXT = 120
MAX_SOURCE_AMOUNT_TEXT = 160
MAX_SOURCE_UNIT_TEXT = 80
MAX_GENERAL_TEXT = 160
MAX_RECIPE_SECONDS = 366 * 24 * 60 * 60
MAX_SOURCE_NUMBER = 1_000_000_000
MAX_EXCLUDED_RECIPE_IDS = 100000
METADATA_SCHEMA_VERSION = "ingredient-topology-v1"
DETAIL_HYDRATION_POLICY_VERSION = "metadata-v3-operator-enabled"
DETAIL_HYDRATION_POLICY_REASON = "metadata_hydration_disabled"
ALLOWED_TMV = frozenset({"TM31", "TM5", "TM6", "TM7"})
SAFE_SOURCE_UNIT_ALIASES = {
    "mg": "mg",
    "milligram": "mg",
    "milligrams": "mg",
    "g": "g",
    "gram": "g",
    "grams": "g",
    "kg": "kg",
    "kilogram": "kg",
    "kilograms": "kg",
    "ml": "ml",
    "milliliter": "ml",
    "milliliters": "ml",
    "millilitre": "ml",
    "millilitres": "ml",
    "cl": "cl",
    "dl": "dl",
    "l": "l",
    "liter": "l",
    "liters": "l",
    "litre": "l",
    "litres": "l",
    "tsp": "tsp",
    "teaspoon": "tsp",
    "teaspoons": "tsp",
    "tbsp": "tbsp",
    "tablespoon": "tbsp",
    "tablespoons": "tbsp",
    "cup": "cup",
    "cups": "cup",
    "oz": "oz",
    "ounce": "oz",
    "ounces": "oz",
    "lb": "lb",
    "lbs": "lb",
    "pound": "lb",
    "pounds": "lb",
    "piece": "piece",
    "pieces": "piece",
    "clove": "clove",
    "cloves": "clove",
    "bunch": "bunch",
    "bunches": "bunch",
    "pinch": "pinch",
    "pinches": "pinch",
    "sprig": "sprig",
    "sprigs": "sprig",
    "handful": "handful",
    "handfuls": "handful",
    "can": "can",
    "cans": "can",
    "jar": "jar",
    "jars": "jar",
    "bottle": "bottle",
    "bottles": "bottle",
    "package": "package",
    "packages": "package",
    "pack": "package",
    "packs": "package",
    "packet": "package",
    "packets": "package",
}
TIME_FACT_ALIASES = {
    "prep": "prep",
    "preptime": "prep",
    "preparation": "prep",
    "preparationtime": "prep",
    "cook": "cook",
    "cooktime": "cook",
    "cooking": "cook",
    "cookingtime": "cook",
    "bake": "cook",
    "baketime": "cook",
    "baking": "cook",
    "bakingtime": "cook",
    "rest": "inactive",
    "resttime": "inactive",
    "resting": "inactive",
    "restingtime": "inactive",
    "wait": "inactive",
    "waittime": "inactive",
    "waiting": "inactive",
    "waitingtime": "inactive",
    "inactive": "inactive",
    "inactivetime": "inactive",
    "active": "active",
    "activetime": "active",
    "total": "total",
    "totaltime": "total",
}
THERMOMIX_VERSION_FIELDS = ("code", "id", "name", "shortName", "value")
DEVICE_SHORT_NAME_FIELDS = (
    "shortName",
    "name",
    "title",
    "label",
    "deviceName",
    "displayName",
)
PROVIDER_REFERENCE_PATTERN = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]*")
PUBLIC_AMOUNT_NUMBER_PATTERN = (
    r"(?:"
    r"\d+\s+\d+\s*/\s*\d+"
    r"|\d+\s*[¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]"
    r"|\d+\s*/\s*\d+"
    r"|\d+(?:[.,]\d+)?"
    r"|[¼½¾⅐⅑⅒⅓⅔⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]"
    r")"
)
PUBLIC_AMOUNT_UNIT_ALIASES = (
    "mg", "milligram", "milligrams",
    "g", "gr", "gram", "grams",
    "kg", "kilogram", "kilograms",
    "ml", "milliliter", "milliliters", "millilitre", "millilitres",
    "cl", "centiliter", "centiliters", "centilitre", "centilitres",
    "dl", "deciliter", "deciliters", "decilitre", "decilitres",
    "l", "liter", "liters", "litre", "litres",
    "tsp", "teaspoon", "teaspoons",
    "tbsp", "tablespoon", "tablespoons",
    "cup", "cups",
    "oz", "ounce", "ounces",
    "lb", "lbs", "pound", "pounds",
    "piece", "pieces", "pc", "pcs",
    "clove", "cloves", "bunch", "bunches",
    "pinch", "pinches", "sprig", "sprigs",
    "handful", "handfuls",
    "can", "cans", "tin", "tins",
    "jar", "jars", "bottle", "bottles",
    "package", "packages", "pack", "packs",
    "packet", "packets", "pkg",
    "fluid ounce", "fluid ounces", "fl oz",
)
PUBLIC_AMOUNT_UNIT_PATTERN = "(?:" + "|".join(
    re.escape(alias).replace(r"\ ", r"\s+")
    for alias in sorted(
        PUBLIC_AMOUNT_UNIT_ALIASES,
        key=len,
        reverse=True,
    )
) + ")"
COOKIDOO_HOSTS = frozenset(
    {
        "cookidoo.at",
        "cookidoo.be",
        "cookidoo.ca",
        "cookidoo.ch",
        "cookidoo.co.uk",
        "cookidoo.com.au",
        "cookidoo.com.cn",
        "cookidoo.com.tr",
        "cookidoo.cz",
        "cookidoo.de",
        "cookidoo.es",
        "cookidoo.fr",
        "cookidoo.international",
        "cookidoo.it",
        "cookidoo.mx",
        "cookidoo.pl",
        "cookidoo.pt",
        "cookidoo.thermomix.com",
    }
)
SEARCH_FIELDS = frozenset(
    {
        "query", "ingredients", "exclude_ingredients", "locale", "tmv",
        "languages", "limit", "page", "exclude_ids", "max_pages",
    }
)
METADATA_FIELDS = frozenset({"locale", "external_ids"})
PLANNER_FIELDS = frozenset(
    {"external_id", "date", "locale", "account_scope", "idempotency_key"}
)
METADATA_FAILURE_KINDS = frozenset(
    {"invalid_id", "invalid_metadata", "locale_mismatch", "not_found"}
)


class BridgeError(Exception):
    """An error safe to expose as a bounded JSON response."""

    def __init__(self, status: int, code: str, message: str) -> None:
        super().__init__(code)
        self.status = status
        self.code = code
        self.message = message


class GatewayConfigurationError(BridgeError):
    """Bridge or account configuration is incomplete."""

    def __init__(self, message: str = "Cookidoo bridge is not configured") -> None:
        super().__init__(503, "bridge_not_configured", message)


class GatewayPolicyDisabledError(BridgeError):
    """Runtime configuration disables metadata hydration."""

    def __init__(self) -> None:
        super().__init__(
            503,
            DETAIL_HYDRATION_POLICY_REASON,
            "Cookidoo metadata hydration is disabled by configuration",
        )


class GatewayPlannerDisabledError(BridgeError):
    """Planner writes are unavailable under default-off policy."""

    def __init__(self, reason: str = "planner_write_disabled") -> None:
        super().__init__(
            503,
            reason,
            "Cookidoo My Week planner writes are disabled",
        )


class GatewayPlannerDriftError(BridgeError):
    """Observed PUT behavior did not preserve the verified pre-state."""

    def __init__(self) -> None:
        super().__init__(
            502,
            "planner_behavior_drift",
            "Cookidoo planner behavior did not match configured semantics",
        )


class GatewayResponseError(BridgeError):
    """The upstream client returned an invalid normalized object."""

    def __init__(self) -> None:
        super().__init__(502, "invalid_upstream_response", "Cookidoo returned invalid metadata")


class GatewayMetadataItemError(Exception):
    """A bounded permanent failure for one direct-ID metadata item."""

    def __init__(self, kind: str) -> None:
        if kind not in METADATA_FAILURE_KINDS:
            raise ValueError("invalid metadata failure kind")
        super().__init__(kind)
        self.kind = kind


def _env_int(name: str, default: int, minimum: int, maximum: int) -> int:
    raw = os.getenv(name, str(default)).strip()
    try:
        value = int(raw)
    except ValueError as exc:
        raise GatewayConfigurationError(f"{name} must be an integer") from exc
    if value < minimum or value > maximum:
        raise GatewayConfigurationError(
            f"{name} must be between {minimum} and {maximum}"
        )
    return value


def _env_bool(name: str, default: bool = False) -> bool:
    raw = os.getenv(name, "true" if default else "false").strip().lower()
    if raw in {"1", "true", "yes", "on"}:
        return True
    if raw in {"0", "false", "no", "off", ""}:
        return False
    raise GatewayConfigurationError(f"{name} must be true or false")


def _planner_semantics(value: object) -> str:
    semantics = str(value).strip().lower()
    if semantics not in {"unknown", "append", "replace"}:
        raise GatewayConfigurationError(
            "COOKIDOO_PLANNER_PUT_SEMANTICS must be unknown, append, or replace"
        )
    return semantics


@dataclass(frozen=True)
class BridgeConfig:
    """Runtime limits and non-account bridge configuration."""

    bridge_token: str = field(repr=False)
    default_locale: str
    cookie_path: Path
    request_timeout_seconds: int
    upstream_timeout_seconds: int
    rate_limit_per_minute: int
    max_concurrency: int
    detail_concurrency: int
    max_results: int
    detail_hydration_enabled: bool = False
    password_login_enabled: bool = False
    planner_write_enabled: bool = False
    planner_put_semantics: str = "unknown"

    @classmethod
    def from_env(cls) -> BridgeConfig:
        return cls(
            bridge_token=os.getenv("COOKIDOO_BRIDGE_TOKEN", "").strip(),
            default_locale=_normalize_locale(
                os.getenv("COOKIDOO_DEFAULT_LOCALE", "en-GB")
            ),
            cookie_path=Path(
                os.getenv("COOKIDOO_COOKIE_PATH", "/data/cookies.json")
            ),
            request_timeout_seconds=_env_int(
                "COOKIDOO_REQUEST_TIMEOUT_SECONDS", 45, 5, 120
            ),
            upstream_timeout_seconds=_env_int(
                "COOKIDOO_UPSTREAM_TIMEOUT_SECONDS", 12, 3, 60
            ),
            rate_limit_per_minute=_env_int(
                "COOKIDOO_RATE_LIMIT_PER_MINUTE", 10, 1, 60
            ),
            max_concurrency=_env_int("COOKIDOO_MAX_CONCURRENCY", 1, 1, 4),
            detail_concurrency=_env_int(
                "COOKIDOO_DETAIL_CONCURRENCY", 1, 1, 4
            ),
            max_results=_env_int("COOKIDOO_MAX_RESULTS", 20, 1, 20),
            detail_hydration_enabled=_env_bool(
                "COOKIDOO_DETAIL_HYDRATION_ENABLED", False
            ),
            password_login_enabled=_env_bool(
                "COOKIDOO_PASSWORD_LOGIN_ENABLED", False
            ),
            planner_write_enabled=_env_bool(
                "COOKIDOO_PLANNER_WRITE_ENABLED", False
            ),
            planner_put_semantics=_planner_semantics(
                os.getenv("COOKIDOO_PLANNER_PUT_SEMANTICS", "unknown")
            ),
        )


def _clean_text(value: object, field_name: str, maximum: int, required: bool) -> str:
    if not isinstance(value, str):
        raise BridgeError(400, "invalid_request", f"{field_name} must be a string")
    value = " ".join(value.split())
    if required and not value:
        raise BridgeError(400, "invalid_request", f"{field_name} is required")
    if len(value) > maximum:
        raise BridgeError(400, "invalid_request", f"{field_name} is too long")
    if any(unicodedata.category(character).startswith("C") for character in value):
        raise BridgeError(
            400, "invalid_request", f"{field_name} contains control characters"
        )
    return value


def _normalize_locale(value: object) -> str:
    locale = _clean_text(value, "locale", 16, True).replace("_", "-")
    match = re.fullmatch(
        r"([A-Za-z]{2,3})(?:-([A-Za-z]{4}))?(?:-([A-Za-z]{2}|[0-9]{3}))?",
        locale,
    )
    if match is None:
        raise BridgeError(400, "invalid_request", "locale is invalid")
    language, script, region = match.groups()
    normalized = language.lower()
    if script:
        normalized += f"-{script.title()}"
    if region:
        normalized += f"-{region if region.isdigit() else region.upper()}"
    return normalized


def _normalize_names(value: object, field_name: str, maximum_items: int) -> tuple[str, ...]:
    if value is None:
        return ()
    if isinstance(value, str) or not isinstance(value, Sequence):
        raise BridgeError(400, "invalid_request", f"{field_name} must be an array")
    if len(value) > maximum_items:
        raise BridgeError(400, "invalid_request", f"{field_name} has too many entries")
    names: dict[str, str] = {}
    for item in value:
        name = _clean_text(item, field_name, 200, True)
        names.setdefault(name.casefold(), name)
    return tuple(names[key] for key in sorted(names))


def _normalize_search_languages(value: object) -> tuple[str, ...]:
    if value is None:
        return ("en",)
    languages = _normalize_names(value, "languages", 5)
    normalized: list[str] = []
    for language in languages:
        code = language.replace("_", "-").lower()
        if re.fullmatch(r"en(?:-[a-z]{2})?", code) is None:
            raise BridgeError(
                400,
                "invalid_request",
                "Cookidoo search languages are forced to English",
            )
        normalized.append("en")
    return tuple(sorted(set(normalized))) or ("en",)


def _normalize_recipe_ids(value: object) -> tuple[str, ...]:
    if value is None:
        return ()
    if isinstance(value, str) or not isinstance(value, Sequence):
        raise BridgeError(400, "invalid_request", "exclude_ids must be an array")
    if len(value) > MAX_EXCLUDED_RECIPE_IDS:
        raise BridgeError(400, "invalid_request", "exclude_ids has too many entries")
    ids: set[str] = set()
    for item in value:
        recipe_id = _clean_text(item, "exclude_ids", 160, True)
        if not re.fullmatch(r"[A-Za-z0-9._:-]+", recipe_id):
            raise BridgeError(400, "invalid_request", "exclude_ids contains an invalid ID")
        ids.add(recipe_id)
    return tuple(sorted(ids))


@dataclass(frozen=True)
class SearchRequest:
    """Validated metadata search parameters."""

    query: str
    ingredients: tuple[str, ...]
    exclude_ingredients: tuple[str, ...]
    locale: str
    tmv: str
    limit: int
    page: int
    exclude_ids: tuple[str, ...]
    max_pages: int
    languages: tuple[str, ...] = ("en",)

    @classmethod
    def from_payload(
        cls, payload: object, config: BridgeConfig
    ) -> SearchRequest:
        if not isinstance(payload, Mapping):
            raise BridgeError(400, "invalid_request", "JSON body must be an object")
        unexpected = sorted(set(payload) - SEARCH_FIELDS)
        if unexpected:
            raise BridgeError(
                400,
                "invalid_request",
                "Unexpected request fields: " + ", ".join(unexpected),
            )

        query_value = payload.get("query", "")
        query = _clean_text(query_value, "query", 240, False)
        ingredients = _normalize_names(
            payload.get("ingredients", ()), "ingredients", MAX_INGREDIENT_FILTERS
        )
        excluded = _normalize_names(
            payload.get("exclude_ingredients", ()),
            "exclude_ingredients",
            MAX_INGREDIENT_FILTERS,
        )
        if not query and not ingredients:
            raise BridgeError(
                400, "invalid_request", "query or ingredients must be provided"
            )
        overlap = {name.casefold() for name in ingredients} & {
            name.casefold() for name in excluded
        }
        if overlap:
            raise BridgeError(
                400,
                "invalid_request",
                "ingredients and exclude_ingredients must not overlap",
            )

        locale = _normalize_locale(payload.get("locale", config.default_locale))
        languages = _normalize_search_languages(payload.get("languages"))
        tmv_raw = payload.get("tmv", "TM6")
        tmv = _clean_text(tmv_raw, "tmv", 8, True).upper()
        if tmv not in ALLOWED_TMV:
            raise BridgeError(400, "invalid_request", "tmv is unsupported")

        limit_raw = payload.get("limit", min(5, config.max_results))
        if isinstance(limit_raw, bool) or not isinstance(limit_raw, int):
            raise BridgeError(400, "invalid_request", "limit must be an integer")
        if limit_raw < 1 or limit_raw > min(MAX_RESPONSE_RECIPES, config.max_results):
            raise BridgeError(
                400,
                "invalid_request",
                f"limit must be between 1 and {min(MAX_RESPONSE_RECIPES, config.max_results)}",
            )
        page_raw = payload.get("page", 0)
        if isinstance(page_raw, bool) or not isinstance(page_raw, int):
            raise BridgeError(400, "invalid_request", "page must be an integer")
        if page_raw < 0 or page_raw > 50:
            raise BridgeError(400, "invalid_request", "page must be between 0 and 50")
        exclude_ids = _normalize_recipe_ids(payload.get("exclude_ids", ()))
        max_pages_raw = payload.get("max_pages", 1)
        if isinstance(max_pages_raw, bool) or not isinstance(max_pages_raw, int):
            raise BridgeError(400, "invalid_request", "max_pages must be an integer")
        if max_pages_raw < 1 or max_pages_raw > 50:
            raise BridgeError(400, "invalid_request", "max_pages must be between 1 and 50")
        return cls(
            query=query,
            ingredients=ingredients,
            exclude_ingredients=excluded,
            locale=locale,
            tmv=tmv,
            limit=limit_raw,
            page=page_raw,
            exclude_ids=exclude_ids,
            max_pages=max_pages_raw,
            languages=languages,
        )


@dataclass(frozen=True)
class MetadataRequest:
    """Validated direct-ID metadata request."""

    locale: str
    external_ids: tuple[str, ...]

    @classmethod
    def from_payload(
        cls, payload: object, config: BridgeConfig
    ) -> MetadataRequest:
        if not isinstance(payload, Mapping):
            raise BridgeError(400, "invalid_request", "JSON body must be an object")
        unexpected = sorted(set(payload) - METADATA_FIELDS)
        if unexpected:
            raise BridgeError(
                400,
                "invalid_request",
                "Unexpected request fields: " + ", ".join(unexpected),
            )
        if "locale" not in payload:
            raise BridgeError(400, "invalid_request", "locale is required")
        locale = _normalize_locale(payload["locale"])
        value = payload.get("external_ids")
        if isinstance(value, str) or not isinstance(value, Sequence):
            raise BridgeError(
                400, "invalid_request", "external_ids must be an array"
            )
        if len(value) < 1 or len(value) > MAX_RESPONSE_RECIPES:
            raise BridgeError(
                400,
                "invalid_request",
                f"external_ids must contain between 1 and {MAX_RESPONSE_RECIPES} IDs",
            )
        external_ids: list[str] = []
        seen: set[str] = set()
        for item in value:
            recipe_id = _clean_text(item, "external_ids", 160, True)
            if not re.fullmatch(r"[A-Za-z0-9._:-]+", recipe_id):
                raise BridgeError(
                    400,
                    "invalid_request",
                    "external_ids contains an invalid ID",
                )
            if recipe_id in seen:
                raise BridgeError(
                    400,
                    "invalid_request",
                    "external_ids must be unique",
                )
            seen.add(recipe_id)
            external_ids.append(recipe_id)
        return cls(locale=locale, external_ids=tuple(external_ids))


@dataclass(frozen=True)
class PlannerRequest:
    """One bounded account-level My Week assignment command."""

    external_id: str
    day: date
    locale: str
    account_scope: str
    idempotency_key: str

    @classmethod
    def from_payload(
        cls, payload: object, config: BridgeConfig
    ) -> PlannerRequest:
        if not isinstance(payload, Mapping):
            raise BridgeError(400, "invalid_request", "JSON body must be an object")
        unexpected = sorted(set(payload) - PLANNER_FIELDS)
        if unexpected:
            raise BridgeError(
                400,
                "invalid_request",
                "Unexpected request fields: " + ", ".join(unexpected),
            )
        external_id = _clean_text(
            payload.get("external_id"), "external_id", 160, True
        )
        if re.fullmatch(r"[A-Za-z0-9._:-]+", external_id) is None:
            raise BridgeError(400, "invalid_request", "external_id is invalid")
        raw_day = _clean_text(payload.get("date"), "date", 10, True)
        try:
            planned_day = date.fromisoformat(raw_day)
        except ValueError as exc:
            raise BridgeError(400, "invalid_request", "date is invalid") from exc
        locale = _normalize_locale(payload.get("locale", config.default_locale))
        account_scope = _clean_text(
            payload.get("account_scope"), "account_scope", 40, True
        )
        if account_scope != "configured_account":
            raise BridgeError(400, "invalid_request", "account_scope is invalid")
        idempotency_key = _clean_text(
            payload.get("idempotency_key"), "idempotency_key", 128, True
        )
        if re.fullmatch(r"[A-Za-z0-9._:-]+", idempotency_key) is None:
            raise BridgeError(400, "invalid_request", "idempotency_key is invalid")
        return cls(
            external_id=external_id,
            day=planned_day,
            locale=locale,
            account_scope=account_scope,
            idempotency_key=idempotency_key,
        )


@dataclass(frozen=True)
class GatewaySearchResult:
    """Allowlisted recipes and bounded page progress."""

    recipes: list[dict[str, object]]
    pages_scanned: int
    last_page: int
    next_page: int
    last_page_had_raw_hits: bool


@dataclass(frozen=True)
class GatewayMetadataResult:
    """Ordered direct-ID metadata outcomes."""

    outcomes: list[dict[str, object]]
    locale: str


@dataclass(frozen=True)
class GatewayPlannerResult:
    """Verified planner write result without account identity or provider bodies."""

    changed: bool
    already_present: bool
    verified: bool
    day: date
    account_scope: str
    reconciled: bool = False


@dataclass(frozen=True)
class SafeIngredientMetadata:
    """Bounded factual ingredient metadata safe to leave the bridge."""

    name: str
    source_quantity: float | None
    source_quantity_max: float | None
    source_unit: str | None
    source_amount_text: str | None
    source_group_index: int
    source_group_position: int
    source_group_title: str | None
    source_ingredient_ref: str | None
    source_default_title: str | None
    source_unit_ref: str | None
    source_optional: bool | None
    source_shopping_category_ref: str | None


@dataclass(frozen=True)
class SafeRecipeMetadata:
    """Allowlisted recipe detail fields with prohibited content omitted."""

    id: str
    name: str
    ingredients: tuple[SafeIngredientMetadata, ...]
    image: str
    url: str
    yield_quantity: float | None
    yield_unit: str | None
    prep_time_seconds: int | None
    cook_time_seconds: int | None
    active_time_seconds: int | None
    inactive_time_seconds: int | None
    total_time_seconds: int | None
    difficulty: str | None
    primary_category: str | None
    devices: tuple[str, ...]
    optional_devices: tuple[str, ...]
    equipment: tuple[str, ...]
    provider_language: str | None
    topology_metrics: dict[str, int]


class SlidingWindowRateLimiter:
    """Small in-memory limiter for the single internal bridge credential."""

    def __init__(self, maximum: int, window_seconds: float = 60.0) -> None:
        self._maximum = maximum
        self._window_seconds = window_seconds
        self._events: deque[float] = deque()
        self._lock = asyncio.Lock()

    async def allow(self) -> bool:
        now = time.monotonic()
        async with self._lock:
            cutoff = now - self._window_seconds
            while self._events and self._events[0] <= cutoff:
                self._events.popleft()
            if len(self._events) >= self._maximum:
                return False
            self._events.append(now)
            return True


def _safe_https_url(value: object, field_name: str, cookidoo_only: bool) -> str:
    if value is None or value == "":
        return ""
    url = _upstream_text(value, field_name, 2048, True)
    parsed = urlparse(url)
    if (
        parsed.scheme.lower() != "https"
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
    ):
        raise GatewayResponseError()
    host = parsed.hostname.lower()
    if cookidoo_only and host not in COOKIDOO_HOSTS:
        raise GatewayResponseError()
    return url


def _safe_image_url(value: object) -> str:
    url = _safe_https_url(value, "image_url", False)
    if not url:
        return ""
    host = urlparse(url).hostname
    if host is None or not (
        host.lower() == "assets.tmecosys.com"
        or host.lower().endswith(".tmecosys.com")
    ):
        raise GatewayResponseError()
    return url


def _validate_upstream_url(url: URL) -> None:
    host = (url.host or "").lower()
    if (
        url.scheme.lower() != "https"
        or not host
        or url.user is not None
        or url.password is not None
        or not (
            host in COOKIDOO_HOSTS
            or host == "vorwerk-digital.com"
            or host.endswith(".vorwerk-digital.com")
            or host == "login.vorwerk.com"
            or host.endswith(".login.vorwerk.com")
        )
    ):
        raise GatewayResponseError()


async def _validate_redirect(
    _session: aiohttp.ClientSession,
    _trace_config_ctx: object,
    params: aiohttp.TraceRequestRedirectParams,
) -> None:
    location = params.response.headers.get("Location", "")
    if not location:
        raise GatewayResponseError()
    _validate_upstream_url(params.url.join(URL(location)))


def _upstream_text(
    value: object, field_name: str, maximum: int, required: bool
) -> str:
    try:
        return _clean_text(value, field_name, maximum, required)
    except BridgeError as exc:
        raise GatewayResponseError() from exc


def _optional_upstream_text(
    value: object, field_name: str, maximum: int
) -> str | None:
    if value is None or value == "":
        return None
    text = _upstream_text(value, field_name, maximum, False)
    return text or None


def _provider_content_language(value: object) -> str | None:
    if value is None or value == "":
        return None
    language = _upstream_text(value, "provider_language", 20, True).replace(
        "_", "-"
    )
    if re.fullmatch(
        r"[A-Za-z]{2,3}(?:-[A-Za-z]{2}|-[A-Za-z]{4}(?:-[A-Za-z]{2})?)?",
        language,
    ) is None:
        raise GatewayResponseError()
    parts = language.split("-")
    normalized = parts[0].lower()
    for part in parts[1:]:
        normalized += "-" + (part.title() if len(part) == 4 else part.upper())
    return normalized


def _optional_upstream_bool(
    mapping: Mapping[str, object],
    key: str,
) -> tuple[bool | None, bool]:
    if key not in mapping:
        return (None, False)
    value = mapping.get(key)
    if value is None:
        return (None, True)
    if not isinstance(value, bool):
        raise GatewayResponseError()
    return (value, True)


def _optional_provider_reference(
    mapping: Mapping[str, object],
    key: str,
) -> tuple[str | None, bool]:
    if key not in mapping:
        return (None, False)
    value = mapping.get(key)
    if value is None or value == "":
        return (None, True)
    reference = _upstream_text(
        value,
        key,
        MAX_PROVIDER_REFERENCE_TEXT,
        True,
    )
    if PROVIDER_REFERENCE_PATTERN.fullmatch(reference) is None:
        raise GatewayResponseError()
    return (reference, True)


def _raw_ingredient_catalog(
    raw: Mapping[str, object],
) -> dict[str, tuple[str | None, bool]]:
    value = raw.get("ingredients")
    if value is None:
        return {}
    entries: list[tuple[object | None, object]]
    if isinstance(value, Mapping):
        if len(value) > MAX_RECIPE_INGREDIENTS:
            raise GatewayResponseError()
        entries = list(value.items())
    elif not isinstance(value, str) and isinstance(value, Sequence):
        if len(value) > MAX_RECIPE_INGREDIENTS:
            raise GatewayResponseError()
        entries = [(None, item) for item in value]
    else:
        raise GatewayResponseError()

    catalog: dict[str, tuple[str | None, bool]] = {}
    for map_key, entry_value in entries:
        entry = _upstream_mapping(entry_value)
        entry_id, id_present = _optional_provider_reference(entry, "id")
        if map_key is not None:
            if not isinstance(map_key, str):
                raise GatewayResponseError()
            keyed_id = _upstream_text(
                map_key,
                "ingredient_catalog_id",
                MAX_PROVIDER_REFERENCE_TEXT,
                True,
            )
            if PROVIDER_REFERENCE_PATTERN.fullmatch(keyed_id) is None:
                raise GatewayResponseError()
            if entry_id is not None and entry_id != keyed_id:
                raise GatewayResponseError()
            entry_id = keyed_id
            id_present = True
        if not id_present or entry_id is None:
            raise GatewayResponseError()

        default_title_present = "defaultTitle" in entry
        default_title = (
            _optional_upstream_text(
                entry.get("defaultTitle"),
                "source_default_title",
                MAX_DEFAULT_TITLE_TEXT,
            )
            if default_title_present
            else None
        )
        if entry_id in catalog:
            # Repeated catalog rows are valid when they add no conflicting title.
            stored_title, stored_title_present = catalog[entry_id]
            if (
                stored_title is not None
                and default_title is not None
                and stored_title != default_title
            ):
                raise GatewayResponseError()
            catalog[entry_id] = (
                stored_title or default_title,
                stored_title_present or default_title_present,
            )
            continue
        catalog[entry_id] = (default_title, default_title_present)
    return catalog


def _catalog_matched_row_reference(
    ingredient: Mapping[str, object],
    catalog: Mapping[str, object],
) -> str | None:
    if "id" not in ingredient:
        return None
    value = ingredient.get("id")
    if not isinstance(value, str):
        return None
    candidate = value.strip()
    if (
        not candidate
        or len(candidate) > MAX_PROVIDER_REFERENCE_TEXT
        or PROVIDER_REFERENCE_PATTERN.fullmatch(candidate) is None
        or candidate not in catalog
    ):
        return None
    return candidate


def _empty_topology_metrics() -> dict[str, int]:
    return {
        "group_count": 0,
        "group_title_key_count": 0,
        "group_title_nonempty_count": 0,
        "group_title_length_total": 0,
        "group_title_length_max": 0,
        "ingredient_count": 0,
        "ingredient_ref_key_count": 0,
        "ingredient_ref_nonempty_count": 0,
        "default_title_key_count": 0,
        "default_title_nonempty_count": 0,
        "unit_ref_key_count": 0,
        "unit_ref_nonempty_count": 0,
        "optional_key_count": 0,
        "optional_true_count": 0,
        "optional_false_count": 0,
        "optional_null_count": 0,
        "shopping_category_ref_key_count": 0,
        "shopping_category_ref_nonempty_count": 0,
    }


def _upstream_number(value: object) -> float | None:
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise GatewayResponseError()
    number = float(value)
    if not math.isfinite(number) or number < 0 or number > MAX_SOURCE_NUMBER:
        raise GatewayResponseError()
    return number


def _upstream_seconds(value: object) -> int | None:
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, int):
        raise GatewayResponseError()
    if value < 0 or value > MAX_RECIPE_SECONDS:
        raise GatewayResponseError()
    return value


def _upstream_sequence(
    value: object, maximum: int, *, optional: bool = False
) -> Sequence[object]:
    if value is None and optional:
        return ()
    if isinstance(value, str) or not isinstance(value, Sequence):
        raise GatewayResponseError()
    if len(value) > maximum:
        raise GatewayResponseError()
    return value


def _upstream_mapping(value: object, *, optional: bool = False) -> Mapping[str, object]:
    if value is None and optional:
        return {}
    if not isinstance(value, Mapping):
        raise GatewayResponseError()
    return value


def _raw_time_facts(
    raw: Mapping[str, object],
) -> tuple[int | None, int | None, int | None, int | None, int | None]:
    values: dict[str, int | None] = {
        "prep": None,
        "cook": None,
        "active": None,
        "inactive": None,
        "total": None,
    }
    seen = {key: False for key in values}
    invalid = set()
    for time_value in _upstream_sequence(raw.get("times"), 20, optional=True):
        time_item = _upstream_mapping(time_value)
        time_type = _optional_upstream_text(
            time_item.get("type"), "time_type", 80
        )
        if time_type is None:
            continue
        alias = re.sub(r"[^a-z0-9]", "", time_type.casefold())
        fact = TIME_FACT_ALIASES.get(alias)
        if fact is None:
            continue
        value, value_max = _raw_quantity(time_item.get("quantity"))
        if value is None or value_max is not None or not value.is_integer():
            seen[fact] = True
            values[fact] = None
            invalid.add(fact)
            continue
        seconds = _upstream_seconds(int(value))
        if fact in invalid:
            continue
        if not seen[fact]:
            values[fact] = seconds
            seen[fact] = True
        elif values[fact] != seconds:
            values[fact] = None
            invalid.add(fact)

    if (
        not seen["prep"]
        and not seen["cook"]
        and not seen["inactive"]
        and values["active"] is not None
        and values["total"] is not None
    ):
        values["inactive"] = max(values["total"] - values["active"], 0)

    return (
        values["prep"],
        values["cook"],
        values["active"],
        values["inactive"],
        values["total"],
    )


def _structured_device_values(value: object) -> Sequence[object]:
    if value is None:
        return ()
    if isinstance(value, str) or isinstance(value, Mapping):
        return (value,)
    return _upstream_sequence(value, MAX_RECIPE_DEVICES)


def _structured_device_name(
    value: object,
    fields: Sequence[str],
    field_name: str,
) -> str | None:
    if isinstance(value, str):
        return _optional_upstream_text(value, field_name, MAX_DEVICE_TEXT)
    if not isinstance(value, Mapping):
        return None
    names: dict[str, str] = {}
    for field in fields:
        if field not in value:
            continue
        name = _optional_upstream_text(
            value.get(field), field_name, MAX_DEVICE_TEXT
        )
        if name is not None:
            names.setdefault(name.casefold(), name)
    if len(names) != 1:
        return None
    return next(iter(names.values()))


def _thermomix_version_name(value: object) -> str | None:
    candidates: Sequence[object]
    if isinstance(value, str):
        candidates = (value,)
    elif isinstance(value, Mapping):
        candidates = tuple(
            value.get(field)
            for field in THERMOMIX_VERSION_FIELDS
            if field in value
        )
    else:
        return None
    versions = set()
    for candidate in candidates:
        name = _optional_upstream_text(
            candidate,
            "thermomix_version",
            MAX_DEVICE_TEXT,
        )
        if name is None:
            continue
        match = re.fullmatch(
            r"(?:thermomix\s*)?(TM31|TM5|TM6|TM7)",
            name,
            re.IGNORECASE,
        )
        if match is not None:
            versions.add(match.group(1).upper())
    if len(versions) != 1:
        return None
    return next(iter(versions))


def _append_device(
    values: list[str],
    seen: set[str],
    name: str | None,
) -> None:
    if name is None:
        return
    key = name.casefold()
    if key in seen or len(values) >= MAX_RECIPE_DEVICES:
        return
    seen.add(key)
    values.append(name)


def _raw_devices(
    raw: Mapping[str, object],
) -> tuple[tuple[str, ...], tuple[str, ...]]:
    devices: list[str] = []
    device_keys: set[str] = set()
    for value in _structured_device_values(raw.get("thermomixVersions")):
        _append_device(
            devices,
            device_keys,
            _thermomix_version_name(value),
        )
    for value in _structured_device_values(raw.get("additionalDevices")):
        _append_device(
            devices,
            device_keys,
            _structured_device_name(
                value,
                DEVICE_SHORT_NAME_FIELDS,
                "additional_device",
            ),
        )

    optional_devices: list[str] = []
    optional_keys: set[str] = set()
    for value in _structured_device_values(raw.get("optionalDevices")):
        name = _structured_device_name(
            value,
            DEVICE_SHORT_NAME_FIELDS,
            "optional_device",
        )
        if name is None or name.casefold() in device_keys:
            continue
        _append_device(optional_devices, optional_keys, name)
    return tuple(devices), tuple(optional_devices)


def _format_source_number(value: float) -> str:
    if value.is_integer():
        return str(int(value))
    return format(value, ".12g")


def _source_amount_text(
    quantity: float | None,
    quantity_max: float | None,
    unit: str | None,
) -> str | None:
    if quantity is None:
        return None
    amount = _format_source_number(quantity)
    if quantity_max is not None:
        amount += " - " + _format_source_number(quantity_max)
    if unit:
        amount += " " + unit
    if len(amount) > MAX_SOURCE_AMOUNT_TEXT:
        raise GatewayResponseError()
    return amount


def _public_amount_text(value: object) -> str | None:
    text = _optional_upstream_text(
        value,
        "source_amount_text",
        MAX_SOURCE_AMOUNT_TEXT,
    )
    if text is None:
        return None
    if re.fullmatch(
        PUBLIC_AMOUNT_NUMBER_PATTERN
        + r"(?:\s*(?:-|–|—)\s*"
        + PUBLIC_AMOUNT_NUMBER_PATTERN
        + r")?(?:\s*"
        + PUBLIC_AMOUNT_UNIT_PATTERN
        + r")?",
        text,
        flags=re.IGNORECASE | re.UNICODE,
    ) is None:
        return None
    return text


def _safe_structured_amount(
    quantity: float | None,
    quantity_max: float | None,
    unit: str | None,
) -> tuple[
    float | None,
    float | None,
    str | None,
    str | None,
]:
    if quantity is None:
        return (None, None, None, None)
    if quantity <= 0 or (
        quantity_max is not None
        and quantity_max <= 0
    ):
        return (None, None, None, None)
    canonical_unit = None
    if unit is not None:
        folded = " ".join(
            unit.strip().lower().replace(".", "").split()
        )
        canonical_unit = SAFE_SOURCE_UNIT_ALIASES.get(folded)
        if canonical_unit is None:
            return (None, None, None, None)
    return (
        quantity,
        quantity_max,
        canonical_unit,
        _source_amount_text(
            quantity,
            quantity_max,
            canonical_unit,
        ),
    )


def _raw_quantity(value: object) -> tuple[float | None, float | None]:
    if value is None:
        return (None, None)
    quantity = _upstream_mapping(value)
    exact = _upstream_number(quantity.get("value"))
    quantity_from = _upstream_number(quantity.get("from"))
    quantity_to = _upstream_number(quantity.get("to"))
    if exact is not None:
        if quantity_from is not None or quantity_to is not None:
            raise GatewayResponseError()
        return (exact, None)
    if quantity_from is None and quantity_to is None:
        raise GatewayResponseError()
    if quantity_from is None or quantity_to is None:
        raise GatewayResponseError()
    if quantity_to < quantity_from:
        raise GatewayResponseError()
    return (quantity_from, quantity_to)


def _raw_image_url(raw: Mapping[str, object]) -> str:
    assets = _upstream_sequence(
        raw.get("descriptiveAssets"),
        MAX_RECIPE_DESCRIPTIVE_ASSETS,
        optional=True,
    )
    for asset_value in assets:
        asset = _upstream_mapping(asset_value)
        for variant in ("square", "portrait", "landscape"):
            value = asset.get(variant)
            if value is None or value == "":
                continue
            template = _upstream_text(value, "image_url", 2048, True)
            return template.replace(
                "{transformation}", "t_web_rdp_recipe_584x480_1_5x"
            )
    return ""


def _safe_recipe_from_raw(
    raw: Mapping[str, object],
    canonical_url: str,
) -> SafeRecipeMetadata:
    ingredients: list[SafeIngredientMetadata] = []
    catalog = _raw_ingredient_catalog(raw)
    topology_metrics = _empty_topology_metrics()
    if "recipeIngredientGroups" not in raw:
        raise GatewayResponseError()
    groups = _upstream_sequence(
        raw["recipeIngredientGroups"],
        MAX_RECIPE_INGREDIENT_GROUPS,
    )
    if not groups:
        raise GatewayResponseError()
    output_group_index = 0
    for group_value in groups:
        group = _upstream_mapping(group_value)
        if "recipeIngredients" not in group:
            raise GatewayResponseError()
        group_title_present = "title" in group
        group_title = (
            _optional_upstream_text(
                group.get("title"),
                "source_group_title",
                MAX_GROUP_TITLE_TEXT,
            )
            if group_title_present
            else None
        )
        group_ingredients = _upstream_sequence(
            group["recipeIngredients"],
            MAX_RECIPE_INGREDIENTS - len(ingredients),
        )
        if not group_ingredients:
            continue
        topology_metrics["group_count"] += 1
        if group_title_present:
            topology_metrics["group_title_key_count"] += 1
        if group_title is not None:
            title_length = len(group_title)
            topology_metrics["group_title_nonempty_count"] += 1
            topology_metrics["group_title_length_total"] += title_length
            topology_metrics["group_title_length_max"] = max(
                topology_metrics["group_title_length_max"],
                title_length,
            )
        for group_position, ingredient_value in enumerate(group_ingredients):
            ingredient = _upstream_mapping(ingredient_value)
            quantity, quantity_max = _raw_quantity(ingredient.get("quantity"))
            unit = _optional_upstream_text(
                ingredient.get("unitNotation"),
                "source_unit",
                MAX_SOURCE_UNIT_TEXT,
            )
            (
                quantity,
                quantity_max,
                unit,
                amount_text,
            ) = _safe_structured_amount(
                quantity,
                quantity_max,
                unit,
            )
            ingredient_ref, ingredient_ref_present = (
                _optional_provider_reference(ingredient, "ingredient_ref")
            )
            local_id, local_id_present = _optional_provider_reference(
                ingredient, "localId"
            )
            if (
                ingredient_ref is not None
                and local_id is not None
                and ingredient_ref != local_id
            ):
                raise GatewayResponseError()
            ingredient_ref = ingredient_ref or local_id
            ingredient_ref_present = (
                ingredient_ref_present or local_id_present
            )
            if ingredient_ref is None:
                ingredient_ref = _catalog_matched_row_reference(
                    ingredient,
                    catalog,
                )
                ingredient_ref_present = ingredient_ref is not None
            catalog_entry = (
                catalog.get(ingredient_ref)
                if ingredient_ref is not None
                else None
            )
            default_title = catalog_entry[0] if catalog_entry else None
            default_title_present = bool(
                catalog_entry is not None and catalog_entry[1]
            )
            unit_ref, unit_ref_present = _optional_provider_reference(
                ingredient, "unit_ref"
            )
            source_optional, optional_present = _optional_upstream_bool(
                ingredient, "optional"
            )
            shopping_category_ref, shopping_category_ref_present = (
                _optional_provider_reference(
                    ingredient,
                    "shoppingCategory_ref",
                )
            )
            ingredients.append(
                SafeIngredientMetadata(
                    name=_upstream_text(
                        ingredient.get("ingredientNotation"),
                        "ingredient_name",
                        200,
                        True,
                    ),
                    source_quantity=quantity,
                    source_quantity_max=quantity_max,
                    source_unit=unit,
                    source_amount_text=amount_text,
                    source_group_index=output_group_index,
                    source_group_position=group_position,
                    source_group_title=group_title,
                    source_ingredient_ref=ingredient_ref,
                    source_default_title=default_title,
                    source_unit_ref=unit_ref,
                    source_optional=source_optional,
                    source_shopping_category_ref=shopping_category_ref,
                )
            )
            topology_metrics["ingredient_count"] += 1
            if ingredient_ref_present:
                topology_metrics["ingredient_ref_key_count"] += 1
            if ingredient_ref is not None:
                topology_metrics["ingredient_ref_nonempty_count"] += 1
            if default_title_present:
                topology_metrics["default_title_key_count"] += 1
            if default_title is not None:
                topology_metrics["default_title_nonempty_count"] += 1
            if unit_ref_present:
                topology_metrics["unit_ref_key_count"] += 1
            if unit_ref is not None:
                topology_metrics["unit_ref_nonempty_count"] += 1
            if optional_present:
                topology_metrics["optional_key_count"] += 1
            if source_optional is True:
                topology_metrics["optional_true_count"] += 1
            elif source_optional is False:
                topology_metrics["optional_false_count"] += 1
            else:
                topology_metrics["optional_null_count"] += 1
            if shopping_category_ref_present:
                topology_metrics[
                    "shopping_category_ref_key_count"
                ] += 1
            if shopping_category_ref is not None:
                topology_metrics[
                    "shopping_category_ref_nonempty_count"
                ] += 1
            if len(ingredients) > MAX_RECIPE_INGREDIENTS:
                raise GatewayResponseError()
        output_group_index += 1
    if not ingredients:
        raise GatewayResponseError()

    serving = _upstream_mapping(raw.get("servingSize"), optional=True)
    serving_quantity, serving_quantity_max = _raw_quantity(serving.get("quantity"))
    serving_unit = _optional_upstream_text(
        serving.get("unitNotation"), "yield_unit", MAX_SOURCE_UNIT_TEXT
    )
    if (
        serving_quantity is None
        or serving_quantity <= 0
        or serving_quantity_max is not None
        or serving_unit is None
    ):
        serving_quantity = None
        serving_unit = None

    (
        prep_time,
        cook_time,
        active_time,
        inactive_time,
        total_time,
    ) = _raw_time_facts(raw)
    devices, optional_devices = _raw_devices(raw)

    primary_category: str | None = None
    categories = _upstream_sequence(raw.get("categories"), 100, optional=True)
    if categories:
        primary_category = _optional_upstream_text(
            _upstream_mapping(categories[0]).get("title"),
            "primary_category",
            MAX_GENERAL_TEXT,
        )

    equipment: list[str] = []
    for utensil_value in _upstream_sequence(
        raw.get("recipeUtensils"), MAX_RECIPE_EQUIPMENT, optional=True
    ):
        utensil = _upstream_mapping(utensil_value)
        name = _optional_upstream_text(
            utensil.get("utensilNotation"), "equipment", 120
        )
        if name is not None:
            equipment.append(name)

    return SafeRecipeMetadata(
        id=_upstream_text(raw.get("id"), "external_id", 160, True),
        name=_upstream_text(raw.get("title"), "title", 400, True),
        ingredients=tuple(ingredients),
        image=_raw_image_url(raw),
        url=canonical_url,
        yield_quantity=serving_quantity,
        yield_unit=serving_unit,
        prep_time_seconds=prep_time,
        cook_time_seconds=cook_time,
        active_time_seconds=active_time,
        inactive_time_seconds=inactive_time,
        total_time_seconds=total_time,
        difficulty=_optional_upstream_text(
            raw.get("difficulty"), "difficulty", 80
        ),
        primary_category=primary_category,
        devices=devices,
        optional_devices=optional_devices,
        equipment=tuple(equipment),
        provider_language=_provider_content_language(
            raw.get("language", raw.get("recipeLanguage"))
        ),
        topology_metrics=topology_metrics,
    )


def _safe_recipe_from_public(details: object) -> SafeRecipeMetadata:
    ingredients: list[SafeIngredientMetadata] = []
    public_ingredients = _upstream_sequence(
        getattr(details, "ingredients", None),
        MAX_RECIPE_INGREDIENTS,
    )
    if not public_ingredients:
        raise GatewayResponseError()
    for group_position, ingredient in enumerate(public_ingredients):
        amount_text = _public_amount_text(getattr(ingredient, "description", None))
        ingredients.append(
            SafeIngredientMetadata(
                name=_upstream_text(
                    getattr(ingredient, "name", ""),
                    "ingredient_name",
                    200,
                    True,
                ),
                source_quantity=None,
                source_quantity_max=None,
                source_unit=None,
                source_amount_text=amount_text,
                source_group_index=0,
                source_group_position=group_position,
                source_group_title=None,
                source_ingredient_ref=None,
                source_default_title=None,
                source_unit_ref=None,
                source_optional=None,
                source_shopping_category_ref=None,
            )
        )

    primary_category: str | None = None
    categories = _upstream_sequence(
        getattr(details, "categories", None), 100, optional=True
    )
    if categories:
        primary_category = _optional_upstream_text(
            getattr(categories[0], "name", None),
            "primary_category",
            MAX_GENERAL_TEXT,
        )

    equipment = tuple(
        _upstream_text(item, "equipment", 120, True)
        for item in _upstream_sequence(
            getattr(details, "utensils", None),
            MAX_RECIPE_EQUIPMENT,
            optional=True,
        )
    )
    topology_metrics = _empty_topology_metrics()
    topology_metrics["group_count"] = 1 if ingredients else 0
    topology_metrics["ingredient_count"] = len(ingredients)
    topology_metrics["optional_null_count"] = len(ingredients)
    active_time = _upstream_seconds(getattr(details, "active_time", None))
    total_time = _upstream_seconds(getattr(details, "total_time", None))
    return SafeRecipeMetadata(
        id=_upstream_text(getattr(details, "id", ""), "external_id", 160, True),
        name=_upstream_text(getattr(details, "name", ""), "title", 400, True),
        ingredients=tuple(ingredients),
        image=_optional_upstream_text(
            getattr(details, "image", None), "image_url", 2048
        )
        or "",
        url=_upstream_text(
            getattr(details, "url", ""), "canonical_url", 2048, True
        ),
        yield_quantity=None,
        yield_unit=None,
        prep_time_seconds=None,
        cook_time_seconds=None,
        active_time_seconds=active_time,
        inactive_time_seconds=(
            max(total_time - active_time, 0)
            if active_time is not None and total_time is not None
            else None
        ),
        total_time_seconds=total_time,
        difficulty=_optional_upstream_text(
            getattr(details, "difficulty", None), "difficulty", 80
        ),
        primary_category=primary_category,
        devices=(),
        optional_devices=(),
        equipment=equipment,
        provider_language=_provider_content_language(
            getattr(details, "language", None)
        ),
        topology_metrics=topology_metrics,
    )


async def _load_safe_recipe_metadata(client: Any, recipe_id: str) -> SafeRecipeMetadata:
    """Load one recipe and retain only bounded factual metadata."""
    if not re.fullmatch(r"[A-Za-z0-9._:-]+", recipe_id):
        raise GatewayResponseError()
    raw_request = getattr(client, "_request_json", None)
    api_endpoint = getattr(client, "api_endpoint", None)
    localization = getattr(client, "localization", None)
    if (
        callable(raw_request)
        and isinstance(api_endpoint, URL)
        and localization is not None
        and isinstance(getattr(localization, "language", None), str)
    ):
        url = api_endpoint / RECIPE_PATH.format(
            language=localization.language,
            id=recipe_id,
        )
        _validate_upstream_url(url)
        raw = await raw_request("get", url, "loading recipe metadata")
        if not isinstance(raw, Mapping):
            raise GatewayResponseError()
        return _safe_recipe_from_raw(raw, str(url))

    details = await client.get_recipe_details(recipe_id)
    return _safe_recipe_from_public(details)


def _allowlisted_recipe(
    hit: object, details: SafeRecipeMetadata, locale: str
) -> dict[str, object]:
    external_id = _upstream_text(
        details.id or getattr(hit, "id", ""),
        "external_id",
        160,
        True,
    )
    hit_id = _upstream_text(getattr(hit, "id", ""), "external_id", 160, True)
    if external_id != hit_id:
        raise GatewayResponseError()
    title = _upstream_text(
        details.name or getattr(hit, "name", ""),
        "title",
        400,
        True,
    )

    image_url = _safe_image_url(
        details.image or getattr(hit, "image", None)
    )
    canonical_url = _safe_https_url(
        details.url or getattr(hit, "url", None),
        "canonical_url",
        True,
    )
    if not canonical_url:
        raise GatewayResponseError()

    return {
        "external_id": external_id,
        "title": title,
        "metadata_schema_version": METADATA_SCHEMA_VERSION,
        "general": {
            "yield_quantity": details.yield_quantity,
            "yield_unit": details.yield_unit,
            "prep_time_seconds": details.prep_time_seconds,
            "cook_time_seconds": details.cook_time_seconds,
            "active_time_seconds": details.active_time_seconds,
            "inactive_time_seconds": details.inactive_time_seconds,
            "total_time_seconds": details.total_time_seconds,
            "difficulty": details.difficulty,
            "primary_category": details.primary_category,
            "devices": list(details.devices),
            "optional_devices": list(details.optional_devices),
            "equipment": list(details.equipment),
        },
        "ingredients": [
            {
                "name": ingredient.name,
                "source_quantity": ingredient.source_quantity,
                "source_quantity_max": ingredient.source_quantity_max,
                "source_unit": ingredient.source_unit,
                "source_amount_text": ingredient.source_amount_text,
                "source_group_index": ingredient.source_group_index,
                "source_group_position": ingredient.source_group_position,
                "source_group_title": ingredient.source_group_title,
                "source_ingredient_ref": ingredient.source_ingredient_ref,
                "source_default_title": ingredient.source_default_title,
                "source_unit_ref": ingredient.source_unit_ref,
                "source_optional": ingredient.source_optional,
                "source_shopping_category_ref": (
                    ingredient.source_shopping_category_ref
                ),
            }
            for ingredient in details.ingredients
        ],
        "topology_metrics": dict(details.topology_metrics),
        "image_url": image_url,
        "canonical_url": canonical_url,
        "locale": locale,
        "provider_language": details.provider_language,
    }


def _canonical_recipe_locale(canonical_url: str) -> str:
    path = urlparse(canonical_url).path
    match = re.fullmatch(r"/recipes/recipe/([^/]+)/[^/]+/?", path)
    if match is None:
        raise ValueError("canonical recipe URL is invalid")
    try:
        return _normalize_locale(match.group(1))
    except BridgeError as exc:
        raise ValueError("canonical recipe locale is invalid") from exc


def _require_canonical_recipe_locale(canonical_url: str, locale: str) -> None:
    if re.fullmatch(
        r"/recipes/recipe/([^/]+)/[^/]+/?",
        urlparse(canonical_url).path,
    ) is None:
        raise GatewayMetadataItemError("invalid_metadata")
    try:
        canonical_locale = _canonical_recipe_locale(canonical_url)
    except ValueError as exc:
        raise GatewayMetadataItemError("locale_mismatch") from exc
    if canonical_locale.casefold() != locale.casefold():
        raise GatewayMetadataItemError("locale_mismatch")


def _metadata_exception_status(exc: BaseException) -> int | None:
    current: BaseException | None = exc
    seen: set[int] = set()
    for _ in range(8):
        if current is None or id(current) in seen:
            break
        seen.add(id(current))
        if isinstance(current, aiohttp.ClientResponseError):
            return int(current.status)
        cause = current.__cause__
        current = cause if isinstance(cause, BaseException) else current.__context__
    return None


def _metadata_failure_kind(exc: BaseException) -> str | None:
    if isinstance(exc, GatewayMetadataItemError):
        return exc.kind
    status = _metadata_exception_status(exc)
    if status in {404, 410}:
        return "not_found"
    if status in {400, 422}:
        return "invalid_id"
    if isinstance(
        exc,
        (GatewayResponseError, CookidooParseException, CookidooResponseException),
    ):
        return "invalid_metadata"
    return None


@dataclass(frozen=True)
class PlannerDayState:
    regular_ids: tuple[str, ...]
    custom_ids: tuple[str, ...]

    @property
    def all_ids(self) -> tuple[str, ...]:
        return tuple(dict.fromkeys([*self.regular_ids, *self.custom_ids]))


def _planner_day_state(
    days: Sequence[object],
    planned_day: date,
) -> PlannerDayState:
    day_key = planned_day.isoformat()
    target = next(
        (
            item
            for item in days
            if str(getattr(item, "id", "")) == day_key
        ),
        None,
    )
    if target is None:
        return PlannerDayState(regular_ids=(), custom_ids=())
    recipes = getattr(target, "recipes", None)
    if isinstance(recipes, str) or not isinstance(recipes, Sequence):
        raise GatewayResponseError()
    custom_value = getattr(target, "customer_recipe_ids", ())
    if isinstance(custom_value, str) or not isinstance(
        custom_value,
        Sequence,
    ):
        raise GatewayResponseError()
    custom_ids: list[str] = []
    custom_seen: set[str] = set()
    for value in custom_value:
        custom_id = _upstream_text(
            value,
            "planner_custom_recipe_id",
            160,
            True,
        )
        if re.fullmatch(r"[A-Za-z0-9._:-]+", custom_id) is None:
            raise GatewayResponseError()
        if custom_id not in custom_seen:
            custom_seen.add(custom_id)
            custom_ids.append(custom_id)
    ids: list[str] = []
    seen: set[str] = set()
    for recipe in recipes:
        recipe_id = _upstream_text(
            getattr(recipe, "id", ""),
            "planner_recipe_id",
            160,
            True,
        )
        if re.fullmatch(r"[A-Za-z0-9._:-]+", recipe_id) is None:
            raise GatewayResponseError()
        if recipe_id not in seen and recipe_id not in custom_seen:
            seen.add(recipe_id)
            ids.append(recipe_id)
    return PlannerDayState(
        regular_ids=tuple(ids),
        custom_ids=tuple(custom_ids),
    )


def _planner_verified(
    state: PlannerDayState,
    target_id: str,
    required: PlannerDayState,
) -> bool:
    return (
        target_id in set(state.regular_ids)
        and set(required.regular_ids).issubset(state.regular_ids)
        and set(required.custom_ids).issubset(state.custom_ids)
    )


ClientFactory = Callable[[aiohttp.ClientSession, CookidooConfig], Any]


class CookidooGateway:
    """Owns the upstream session, lazy login, cookies, and allowlisting."""

    def __init__(
        self,
        session: aiohttp.ClientSession,
        config: BridgeConfig,
        localizations: Sequence[CookidooLocalizationConfig],
        client_factory: ClientFactory = Cookidoo,
        bounded_session: aiohttp.ClientSession | None = None,
    ) -> None:
        if not localizations:
            raise GatewayConfigurationError("No Cookidoo localizations are available")
        self._session = session
        self._bounded_session = bounded_session or session
        self._config = config
        self._localizations = tuple(localizations)
        self._client_factory = client_factory
        self._clients: dict[str, Any] = {}
        self._cookies_loaded = False
        self._cookie_available = False
        self._authenticated: set[str] = set()
        self._login_lock = asyncio.Lock()
        self._detail_semaphore = asyncio.Semaphore(config.detail_concurrency)
        self._planner_semaphore = asyncio.Semaphore(1)
        self._planner_circuit_open_until = 0.0
        config.cookie_path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        os.chmod(config.cookie_path.parent, 0o700)

    @classmethod
    async def create(cls, config: BridgeConfig) -> CookidooGateway:
        timeout = aiohttp.ClientTimeout(
            total=config.upstream_timeout_seconds,
            connect=min(5, config.upstream_timeout_seconds),
        )
        trace_config = aiohttp.TraceConfig()
        trace_config.on_request_redirect.append(_validate_redirect)
        bounded_trace_config = aiohttp.TraceConfig()
        bounded_trace_config.on_request_redirect.append(
            _validate_redirect
        )
        cookie_jar = aiohttp.CookieJar(unsafe=True)
        session = aiohttp.ClientSession(
            cookie_jar=cookie_jar,
            timeout=timeout,
            trust_env=False,
            trace_configs=[trace_config],
        )
        bounded_session = aiohttp.ClientSession(
            auto_decompress=False,
            cookie_jar=cookie_jar,
            timeout=timeout,
            trust_env=False,
            trace_configs=[bounded_trace_config],
        )
        try:
            localizations = await get_localization_options()
            return cls(
                session,
                config,
                localizations,
                bounded_session=bounded_session,
            )
        except BaseException:
            await session.close()
            await bounded_session.close()
            raise

    async def close(self) -> None:
        await self._session.close()
        if self._bounded_session is not self._session:
            await self._bounded_session.close()

    def _select_localization(
        self, locale: str, *, exact: bool = False
    ) -> CookidooLocalizationConfig:
        locale_lower = locale.lower()
        exact_matches = [
            item
            for item in self._localizations
            if item.language.lower() == locale_lower
        ]
        if exact_matches:
            return sorted(
                exact_matches, key=lambda item: (item.country_code, item.url)
            )[0]
        if exact:
            raise BridgeError(400, "unsupported_locale", "locale is unsupported")

        language = locale_lower.split("-", 1)[0]
        candidates = [
            item
            for item in self._localizations
            if item.language.lower().split("-", 1)[0] == language
        ]
        if not candidates:
            raise BridgeError(400, "unsupported_locale", "locale is unsupported")

        default_lower = self._config.default_locale.lower()
        for item in candidates:
            if item.language.lower() == default_lower:
                return item
        return sorted(
            candidates, key=lambda item: (item.language, item.country_code, item.url)
        )[0]

    def _client_for(self, localization: CookidooLocalizationConfig) -> Any:
        key = f"{localization.language}|{localization.url}"
        client = self._clients.get(key)
        if client is None:
            client = self._client_factory(
                self._session,
                CookidooConfig(
                    email=os.getenv("COOKIDOO_EMAIL", ""),
                    password=os.getenv("COOKIDOO_PASSWORD", ""),
                    localization=localization,
                ),
            )
            self._clients[key] = client
        return client

    async def _load_cookies_once(self, client: Any) -> None:
        if self._cookies_loaded:
            return
        self._cookies_loaded = True
        if not self._config.cookie_path.is_file():
            return
        try:
            client.load_cookies(self._config.cookie_path)
        except CookidooConfigException:
            LOGGER.warning("stored_cookie_invalid login_required=true")
            self._config.cookie_path.unlink(missing_ok=True)
            return
        self._cookie_available = True

    def _save_cookies(self, client: Any) -> None:
        old_umask = os.umask(0o077)
        try:
            client.save_cookies(self._config.cookie_path)
        finally:
            os.umask(old_umask)
        os.chmod(self._config.cookie_path, stat.S_IRUSR | stat.S_IWUSR)
        self._cookie_available = True

    async def _login(self, client: Any, key: str, force: bool) -> None:
        async with self._login_lock:
            if not force and key in self._authenticated:
                return
            if not self._config.password_login_enabled:
                raise GatewayConfigurationError(
                    "Cookidoo password login is disabled; refresh the persisted cookie"
                )
            if not os.getenv("COOKIDOO_EMAIL", "").strip() or not os.getenv(
                "COOKIDOO_PASSWORD", ""
            ).strip():
                raise GatewayConfigurationError(
                    "Cookidoo credentials are required for login"
                )
            await client.login()
            self._save_cookies(client)
            self._authenticated.add(key)

    async def _with_lazy_login(
        self,
        client: Any,
        key: str,
        operation: Callable[[], Awaitable[Any]],
    ) -> Any:
        await self._load_cookies_once(client)
        logged_in_now = False
        if key not in self._authenticated:
            if self._cookie_available:
                try:
                    if self._config.detail_hydration_enabled:
                        api_endpoint = getattr(
                            client,
                            "api_endpoint",
                            None,
                        )
                        localization = getattr(
                            client,
                            "localization",
                            None,
                        )
                        if (
                            not isinstance(api_endpoint, URL)
                            or localization is None
                        ):
                            raise GatewayConfigurationError(
                                "Cookidoo account verification is unavailable"
                            )
                        profile_url = (
                            api_endpoint
                            / COMMUNITY_PROFILE_PATH.format(
                                **localization.__dict__
                            )
                        )
                        await self._bounded_request_json(
                            client,
                            "get",
                            profile_url,
                            "loading user info",
                        )
                    else:
                        await client.get_user_info()
                except CookidooAuthException:
                    self._cookie_available = False
                else:
                    self._authenticated.add(key)
            if key not in self._authenticated:
                await self._login(client, key, force=False)
                logged_in_now = True
        try:
            return await operation()
        except CookidooAuthException:
            if logged_in_now:
                raise
            await self._login(client, key, force=True)
            return await operation()

    async def _bounded_request_json(
        self,
        client: Any,
        method: str,
        url: URL,
        operation: str,
        *,
        params: Mapping[str, str] | None = None,
    ) -> Mapping[str, object]:
        decoded, _response_url = await self._bounded_request_json_with_url(
            client,
            method,
            url,
            operation,
            params=params,
        )
        return decoded

    async def _bounded_request_json_with_url(
        self,
        client: Any,
        method: str,
        url: URL,
        operation: str,
        *,
        params: Mapping[str, str] | None = None,
    ) -> tuple[Mapping[str, object], URL]:
        _validate_upstream_url(url)
        headers = getattr(client, "_api_headers", None)
        if not isinstance(headers, Mapping):
            raise GatewayConfigurationError(
                "Cookidoo client headers are unavailable"
            )
        request_headers = dict(headers)
        request_headers["Accept-Encoding"] = "gzip, deflate"
        try:
            async with self._bounded_session.request(
                method,
                url,
                headers=request_headers,
                params=dict(params or {}),
            ) as response:
                if response.status == 401:
                    raise_auth = getattr(
                        client,
                        "_raise_auth_exception",
                        None,
                    )
                    if callable(raise_auth):
                        raise_auth(operation)
                    raise CookidooAuthException(
                        f"{operation} authentication failed"
                    )
                response.raise_for_status()
                encoding = response.headers.get(
                    "Content-Encoding",
                    "",
                ).strip().lower()
                if encoding in {"", "identity"}:
                    decompressor = None
                elif encoding == "gzip":
                    decompressor = zlib.decompressobj(
                        16 + zlib.MAX_WBITS
                    )
                elif encoding == "deflate":
                    decompressor = zlib.decompressobj()
                else:
                    raise GatewayResponseError()
                body = bytearray()
                compressed_bytes = 0
                async for chunk in response.content.iter_chunked(65536):
                    compressed_bytes += len(chunk)
                    if compressed_bytes > MAX_UPSTREAM_RESPONSE_BYTES:
                        raise GatewayResponseError()
                    if decompressor is None:
                        body.extend(chunk)
                    else:
                        remaining = (
                            MAX_UPSTREAM_RESPONSE_BYTES - len(body)
                        )
                        try:
                            decoded_chunk = decompressor.decompress(
                                chunk,
                                remaining + 1,
                            )
                        except zlib.error as exc:
                            raise GatewayResponseError() from exc
                        body.extend(decoded_chunk)
                        if decompressor.unconsumed_tail:
                            raise GatewayResponseError()
                    if len(body) > MAX_UPSTREAM_RESPONSE_BYTES:
                        raise GatewayResponseError()
                if decompressor is not None:
                    remaining = MAX_UPSTREAM_RESPONSE_BYTES - len(body)
                    try:
                        body.extend(decompressor.flush(remaining + 1))
                    except zlib.error as exc:
                        raise GatewayResponseError() from exc
                    if (
                        len(body) > MAX_UPSTREAM_RESPONSE_BYTES
                        or decompressor.unused_data
                    ):
                        raise GatewayResponseError()
                response_url = URL(str(response.url))
                _validate_upstream_url(response_url)
        except (
            CookidooAuthException,
            GatewayResponseError,
        ):
            raise
        except (aiohttp.ClientError, TimeoutError) as exc:
            raise CookidooRequestException(
                f"{operation} failed"
            ) from exc
        try:
            decoded = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise CookidooParseException(
                f"{operation} returned invalid JSON"
            ) from exc
        if not isinstance(decoded, Mapping):
            raise GatewayResponseError()
        return decoded, response_url

    async def _load_safe_recipe_metadata(
        self,
        client: Any,
        recipe_id: str,
    ) -> SafeRecipeMetadata:
        if not re.fullmatch(r"[A-Za-z0-9._:-]+", recipe_id):
            raise GatewayResponseError()
        api_endpoint = getattr(client, "api_endpoint", None)
        localization = getattr(client, "localization", None)
        if not isinstance(api_endpoint, URL) or localization is None:
            raise GatewayConfigurationError(
                "Cookidoo raw metadata adapter is unavailable"
            )
        url = api_endpoint / RECIPE_PATH.format(
            language=localization.language,
            id=recipe_id,
        )
        raw, response_url = await self._bounded_request_json_with_url(
            client,
            "get",
            url,
            "loading recipe metadata",
        )
        return _safe_recipe_from_raw(raw, str(response_url))

    async def search(self, request: SearchRequest) -> GatewaySearchResult:
        if not self._config.detail_hydration_enabled:
            raise GatewayPolicyDisabledError()
        localization = self._select_localization(request.locale)
        client = self._client_for(localization)
        key = f"{localization.language}|{localization.url}"
        excluded = set(request.exclude_ids)
        recipes: list[dict[str, object]] = []
        pages_scanned = 0
        last_page = request.page
        last_page_had_raw_hits = False

        for page in range(
            request.page,
            min(51, request.page + request.max_pages),
        ):
            params = {
                "query": request.query,
                "languages": ",".join(request.languages),
                "page": str(page),
                "pageSize": str(request.limit),
                "tmv": request.tmv,
            }
            if request.ingredients:
                params["ingredients"] = ",".join(request.ingredients)
            if request.exclude_ingredients:
                params["excludeIngredients"] = ",".join(
                    request.exclude_ingredients
                )
            search_url = (
                getattr(client, "api_endpoint", URL(""))
                / "search"
                / localization.language.split("-", 1)[0]
            )
            operation = lambda: self._bounded_request_json(
                client,
                "get",
                search_url,
                "search recipes",
                params=params,
            )
            raw_result = await self._with_lazy_login(
                client,
                key,
                operation,
            )
            try:
                result = cookidoo_search_result_from_json(
                    raw_result,
                    localization,
                )
            except (KeyError, TypeError, ValueError) as exc:
                raise CookidooParseException(
                    "Search recipes failed during parsing"
                ) from exc
            raw_hits = getattr(result, "recipes", None)
            if (
                isinstance(raw_hits, str)
                or not isinstance(raw_hits, Sequence)
                or len(raw_hits) > 200
            ):
                raise GatewayResponseError()
            pages_scanned += 1
            last_page = page
            last_page_had_raw_hits = len(raw_hits) > 0
            if not raw_hits:
                break

            for hit in raw_hits:
                external_id = _upstream_text(
                    getattr(hit, "id", ""),
                    "external_id",
                    160,
                    True,
                )
                if external_id in excluded:
                    continue
                try:
                    async with self._detail_semaphore:
                        details = await self._with_lazy_login(
                            client,
                            key,
                            lambda external_id=external_id:
                                self._load_safe_recipe_metadata(
                                    client, external_id
                                ),
                        )
                    recipe = _allowlisted_recipe(
                        hit,
                        details,
                        localization.language,
                    )
                    _require_canonical_recipe_locale(
                        details.url,
                        localization.language,
                    )
                except BaseException as exc:
                    if _metadata_failure_kind(exc) is not None:
                        continue
                    raise
                recipes.append(recipe)
                excluded.add(external_id)
                if len(recipes) >= request.limit:
                    break
            if len(recipes) >= request.limit:
                break

        return GatewaySearchResult(
            recipes=recipes,
            pages_scanned=pages_scanned,
            last_page=last_page,
            next_page=last_page + 1,
            last_page_had_raw_hits=last_page_had_raw_hits,
        )

    async def metadata(self, request: MetadataRequest) -> GatewayMetadataResult:
        if not self._config.detail_hydration_enabled:
            raise GatewayPolicyDisabledError()
        localization = self._select_localization(
            request.locale,
            exact=True,
        )
        client = self._client_for(localization)
        key = f"{localization.language}|{localization.url}"
        outcomes: list[dict[str, object]] = []
        for external_id in request.external_ids:
            try:
                async with self._detail_semaphore:
                    details = await self._with_lazy_login(
                        client,
                        key,
                        lambda external_id=external_id:
                            self._load_safe_recipe_metadata(
                                client, external_id
                            ),
                    )
                _require_canonical_recipe_locale(
                    details.url,
                    localization.language,
                )
                recipe = _allowlisted_recipe(
                    SimpleNamespace(
                        id=external_id,
                        name="",
                        image="",
                        url="",
                    ),
                    details,
                    localization.language,
                )
                outcomes.append(
                    {
                        "external_id": external_id,
                        "status": "succeeded",
                        "recipe": recipe,
                    }
                )
            except BaseException as exc:
                failure_kind = _metadata_failure_kind(exc)
                if failure_kind is None:
                    raise
                outcomes.append(
                    {
                        "external_id": external_id,
                        "status": "failed",
                        "error_kind": failure_kind,
                    }
                )
        return GatewayMetadataResult(
            outcomes=outcomes,
            locale=localization.language,
        )

    async def _planner_read(
        self,
        client: Any,
        key: str,
        planned_day: date,
        runner: Callable[
            [Callable[[], Awaitable[Any]]],
            Awaitable[Any],
        ] | None = None,
    ) -> PlannerDayState:
        operation = lambda: client.get_recipes_in_calendar_week(planned_day)
        days = await (
            runner(operation)
            if runner is not None
            else self._with_lazy_login(client, key, operation)
        )
        if isinstance(days, str) or not isinstance(days, Sequence):
            raise GatewayResponseError()
        return _planner_day_state(days, planned_day)

    def _planner_open_circuit(self, status: int) -> None:
        delay = 15 * 60 if status == 403 else 60
        self._planner_circuit_open_until = max(
            self._planner_circuit_open_until,
            time.monotonic() + delay,
        )

    async def planner_add(
        self,
        request: PlannerRequest,
    ) -> GatewayPlannerResult:
        if not self._config.planner_write_enabled:
            raise GatewayPlannerDisabledError()
        semantics = _planner_semantics(
            self._config.planner_put_semantics
        )
        if semantics == "unknown":
            raise GatewayPlannerDisabledError("planner_put_semantics_unknown")
        if time.monotonic() < self._planner_circuit_open_until:
            raise BridgeError(
                503,
                "planner_circuit_open",
                "Cookidoo planner circuit is open",
            )
        localization = self._select_localization(
            request.locale,
            exact=True,
        )
        client = self._client_for(localization)
        key = f"{localization.language}|{localization.url}"
        stale_relogin_used = False

        async def planner_run(
            operation: Callable[[], Awaitable[Any]],
        ) -> Any:
            nonlocal stale_relogin_used
            await self._load_cookies_once(client)
            logged_in_now = False
            if not self._cookie_available and key not in self._authenticated:
                await self._login(client, key, force=False)
                logged_in_now = True
            try:
                return await operation()
            except CookidooAuthException:
                if logged_in_now or stale_relogin_used:
                    raise
                stale_relogin_used = True
                await self._login(client, key, force=True)
                return await operation()

        async with self._planner_semaphore:
            pre_state = await self._planner_read(
                client,
                key,
                request.day,
                planner_run,
            )
            if request.external_id in pre_state.all_ids:
                return GatewayPlannerResult(
                    changed=False,
                    already_present=True,
                    verified=True,
                    day=request.day,
                    account_scope=request.account_scope,
                )
            if semantics == "replace" and pre_state.custom_ids:
                raise GatewayPlannerDisabledError(
                    "planner_replace_custom_recipes_present"
                )
            write_ids = (
                [request.external_id]
                if semantics == "append"
                else list(
                    dict.fromkeys([
                        *pre_state.regular_ids,
                        request.external_id,
                    ])
                )
            )

            async def write_once() -> None:
                await planner_run(
                    lambda: client.add_recipes_to_calendar(
                        request.day,
                        write_ids,
                    ),
                )

            reconciled = False
            try:
                await write_once()
            except BaseException as exc:
                status = _metadata_exception_status(exc)
                if status in {403, 429}:
                    self._planner_open_circuit(status)
                    raise
                if not isinstance(
                    exc,
                    (TimeoutError, CookidooRequestException, aiohttp.ClientError),
                ):
                    raise
                reconciled_state = await self._planner_read(
                    client,
                    key,
                    request.day,
                    planner_run,
                )
                if _planner_verified(
                    reconciled_state,
                    request.external_id,
                    pre_state,
                ):
                    return GatewayPlannerResult(
                        changed=True,
                        already_present=False,
                        verified=True,
                        day=request.day,
                        account_scope=request.account_scope,
                        reconciled=True,
                    )
                try:
                    await write_once()
                except BaseException as retry_exc:
                    retry_status = _metadata_exception_status(retry_exc)
                    if retry_status in {403, 429}:
                        self._planner_open_circuit(retry_status)
                        raise
                    if not isinstance(
                        retry_exc,
                        (
                            TimeoutError,
                            CookidooRequestException,
                            aiohttp.ClientError,
                        ),
                    ):
                        raise
                    final_state = await self._planner_read(
                        client,
                        key,
                        request.day,
                        planner_run,
                    )
                    if not _planner_verified(
                        final_state,
                        request.external_id,
                        pre_state,
                    ):
                        raise
                    return GatewayPlannerResult(
                        changed=True,
                        already_present=False,
                        verified=True,
                        day=request.day,
                        account_scope=request.account_scope,
                        reconciled=True,
                    )
                reconciled = True

            post_state = await self._planner_read(
                client,
                key,
                request.day,
                planner_run,
            )
            if not _planner_verified(
                post_state,
                request.external_id,
                pre_state,
            ):
                raise GatewayPlannerDriftError()
            return GatewayPlannerResult(
                changed=True,
                already_present=False,
                verified=True,
                day=request.day,
                account_scope=request.account_scope,
                reconciled=reconciled,
            )


CONFIG_KEY = web.AppKey("config", BridgeConfig)
RATE_LIMITER_KEY = web.AppKey("rate_limiter", SlidingWindowRateLimiter)
REQUEST_SEMAPHORE_KEY = web.AppKey("request_semaphore", asyncio.Semaphore)
GATEWAY_KEY = web.AppKey("gateway", CookidooGateway)


@web.middleware
async def error_middleware(
    request: web.Request, handler: Callable[[web.Request], Awaitable[web.StreamResponse]]
) -> web.StreamResponse:
    try:
        return await handler(request)
    except BridgeError as exc:
        LOGGER.warning("request_rejected code=%s status=%s", exc.code, exc.status)
        return web.json_response(
            {"error": exc.code, "message": exc.message}, status=exc.status
        )
    except TimeoutError:
        LOGGER.warning("request_failed code=upstream_timeout")
        return web.json_response(
            {"error": "upstream_timeout", "message": "Cookidoo request timed out"},
            status=504,
        )
    except CookidooAuthException:
        LOGGER.warning("request_failed code=cookidoo_auth_failed")
        return web.json_response(
            {
                "error": "cookidoo_auth_failed",
                "message": "Cookidoo authentication failed",
            },
            status=502,
        )
    except CookidooException as exc:
        upstream_status = _metadata_exception_status(exc)
        if upstream_status in {403, 429}:
            code = (
                "cookidoo_upstream_forbidden"
                if upstream_status == 403
                else "cookidoo_upstream_rate_limited"
            )
            LOGGER.warning("request_failed code=%s", code)
            return web.json_response(
                {
                    "error": code,
                    "message": "Cookidoo pilot circuit break required",
                },
                status=upstream_status,
            )
        LOGGER.warning(
            "request_failed code=cookidoo_request_failed type=%s",
            type(exc).__name__,
        )
        return web.json_response(
            {
                "error": "cookidoo_request_failed",
                "message": "Cookidoo request failed",
            },
            status=502,
        )
    except web.HTTPRequestEntityTooLarge:
        return web.json_response(
            {"error": "body_too_large", "message": "Request body is too large"},
            status=413,
        )
    except web.HTTPException as exc:
        return web.json_response(
            {"error": "http_error", "message": "Invalid HTTP request"},
            status=exc.status,
        )
    except Exception as exc:
        LOGGER.error("request_failed code=internal_error type=%s", type(exc).__name__)
        return web.json_response(
            {"error": "internal_error", "message": "Internal bridge error"},
            status=500,
        )


def _policy_capabilities(config: BridgeConfig) -> dict[str, object]:
    detail_enabled = config.detail_hydration_enabled
    planner_available = (
        config.planner_write_enabled
        and config.planner_put_semantics in {"append", "replace"}
    )
    return {
        "detail_hydration": detail_enabled,
        "metadata_hydration": detail_enabled,
        "ingredient_aware_discovery": detail_enabled,
        "planner_write": planner_available,
        "put_semantics": (
            config.planner_put_semantics
            if planner_available
            else "unknown"
        ),
        "account_scope": "configured_account",
        "reason": (
            None if detail_enabled else DETAIL_HYDRATION_POLICY_REASON
        ),
        "policy_version": DETAIL_HYDRATION_POLICY_VERSION,
    }


async def health_handler(request: web.Request) -> web.Response:
    return web.json_response(
        {
            "status": "ok",
            "service": "cookidoo-bridge",
            "build_revision": BUILD_REVISION,
            "capabilities": _policy_capabilities(request.app[CONFIG_KEY]),
        }
    )


async def capabilities_handler(request: web.Request) -> web.Response:
    return web.json_response(_policy_capabilities(request.app[CONFIG_KEY]))


def _authorize(request: web.Request, config: BridgeConfig) -> None:
    if not config.bridge_token:
        raise GatewayConfigurationError("COOKIDOO_BRIDGE_TOKEN is required")
    header = request.headers.get("Authorization", "")
    prefix = "Bearer "
    if not header.startswith(prefix) or not hmac.compare_digest(
        header[len(prefix) :], config.bridge_token
    ):
        raise BridgeError(401, "unauthorized", "Bearer token is invalid")


async def search_handler(request: web.Request) -> web.Response:
    config = request.app[CONFIG_KEY]
    _authorize(request, config)
    limiter = request.app[RATE_LIMITER_KEY]
    if not await limiter.allow():
        raise BridgeError(429, "rate_limited", "Bridge rate limit exceeded")
    if not request.content_type == "application/json":
        raise BridgeError(415, "unsupported_media_type", "Content-Type must be application/json")
    try:
        payload = await request.json(loads=json.loads)
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise BridgeError(400, "invalid_json", "Request body is not valid JSON") from None
    search_request = SearchRequest.from_payload(payload, config)
    if not config.detail_hydration_enabled:
        raise GatewayPolicyDisabledError()
    gateway = request.app.get(GATEWAY_KEY)
    if gateway is None:
        raise GatewayConfigurationError(
            "Cookidoo discovery gateway is unavailable"
        )
    async with request.app[REQUEST_SEMAPHORE_KEY]:
        result = await asyncio.wait_for(
            gateway.search(search_request),
            timeout=config.request_timeout_seconds,
        )
    return _bounded_json_response(
        {
            "recipes": result.recipes,
            "count": len(result.recipes),
            "pages_scanned": result.pages_scanned,
            "last_page": result.last_page,
            "next_page": result.next_page,
            "last_page_had_raw_hits":
                result.last_page_had_raw_hits,
        }
    )


async def metadata_handler(request: web.Request) -> web.Response:
    config = request.app[CONFIG_KEY]
    _authorize(request, config)
    limiter = request.app[RATE_LIMITER_KEY]
    if not await limiter.allow():
        raise BridgeError(429, "rate_limited", "Bridge rate limit exceeded")
    if not request.content_type == "application/json":
        raise BridgeError(
            415,
            "unsupported_media_type",
            "Content-Type must be application/json",
        )
    try:
        payload = await request.json(loads=json.loads)
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise BridgeError(
            400, "invalid_json", "Request body is not valid JSON"
        ) from None
    metadata_request = MetadataRequest.from_payload(payload, config)
    if not config.detail_hydration_enabled:
        raise GatewayPolicyDisabledError()
    gateway = request.app.get(GATEWAY_KEY)
    if gateway is None:
        raise GatewayConfigurationError(
            "Cookidoo metadata gateway is unavailable"
        )
    async with request.app[REQUEST_SEMAPHORE_KEY]:
        result = await asyncio.wait_for(
            gateway.metadata(metadata_request),
            timeout=config.request_timeout_seconds,
        )
    succeeded = sum(
        1 for item in result.outcomes
        if item["status"] == "succeeded"
    )
    return _bounded_json_response(
        {
            "outcomes": result.outcomes,
            "count": len(result.outcomes),
            "succeeded_count": succeeded,
            "failed_count": len(result.outcomes) - succeeded,
            "locale": result.locale,
            "metadata_schema_version": METADATA_SCHEMA_VERSION,
        }
    )


async def planner_capabilities_handler(request: web.Request) -> web.Response:
    config = request.app[CONFIG_KEY]
    _authorize(request, config)
    if not request.content_type == "application/json":
        raise BridgeError(
            415,
            "unsupported_media_type",
            "Content-Type must be application/json",
        )
    try:
        payload = await request.json(loads=json.loads)
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise BridgeError(
            400,
            "invalid_json",
            "Request body is not valid JSON",
        ) from None
    if payload != {"capability": "recipe_planner_v1"}:
        raise BridgeError(
            400,
            "invalid_request",
            "Planner capability request is invalid",
        )
    capabilities = _policy_capabilities(config)
    return web.json_response(
        {
            "planner_write": capabilities["planner_write"],
            "put_semantics": capabilities["put_semantics"],
            "account_scope": "configured_account",
        }
    )


async def planner_add_handler(request: web.Request) -> web.Response:
    config = request.app[CONFIG_KEY]
    _authorize(request, config)
    limiter = request.app[RATE_LIMITER_KEY]
    if not await limiter.allow():
        raise BridgeError(429, "rate_limited", "Bridge rate limit exceeded")
    if not request.content_type == "application/json":
        raise BridgeError(
            415,
            "unsupported_media_type",
            "Content-Type must be application/json",
        )
    try:
        payload = await request.json(loads=json.loads)
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise BridgeError(
            400,
            "invalid_json",
            "Request body is not valid JSON",
        ) from None
    planner_request = PlannerRequest.from_payload(payload, config)
    gateway = request.app.get(GATEWAY_KEY)
    if gateway is None:
        raise GatewayConfigurationError(
            "Cookidoo planner gateway is unavailable"
        )
    async with request.app[REQUEST_SEMAPHORE_KEY]:
        result = await asyncio.wait_for(
            gateway.planner_add(planner_request),
            timeout=config.request_timeout_seconds,
        )
    return web.json_response(
        {
            "changed": result.changed,
            "already_present": result.already_present,
            "verified": result.verified,
            "date": result.day.isoformat(),
            "account_scope": result.account_scope,
            "reconciled": result.reconciled,
        }
    )


async def _gateway_context(app: web.Application):
    gateway = await CookidooGateway.create(app[CONFIG_KEY])
    app[GATEWAY_KEY] = gateway
    try:
        yield
    finally:
        await gateway.close()


def _bounded_json_response(
    payload: Mapping[str, object],
) -> web.Response:
    body = json.dumps(
        payload,
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")
    if len(body) > MAX_RESPONSE_BYTES:
        raise BridgeError(
            502,
            "response_too_large",
            "Bridge response exceeds the configured limit",
        )
    return web.Response(
        body=body,
        content_type="application/json",
    )


def create_app(
    config: BridgeConfig | None = None, gateway: CookidooGateway | None = None
) -> web.Application:
    config = config or BridgeConfig.from_env()
    app = web.Application(client_max_size=MAX_BODY_BYTES, middlewares=[error_middleware])
    app[CONFIG_KEY] = config
    app[RATE_LIMITER_KEY] = SlidingWindowRateLimiter(config.rate_limit_per_minute)
    app[REQUEST_SEMAPHORE_KEY] = asyncio.Semaphore(config.max_concurrency)

    if gateway is not None:
        app[GATEWAY_KEY] = gateway
    elif config.detail_hydration_enabled or (
        config.planner_write_enabled
        and config.planner_put_semantics in {"append", "replace"}
    ):
        app.cleanup_ctx.append(_gateway_context)

    app.router.add_get("/health", health_handler)
    app.router.add_get("/v1/capabilities", capabilities_handler)
    app.router.add_post("/v1/search", search_handler)
    app.router.add_post("/v1/metadata", metadata_handler)
    app.router.add_post(
        "/v1/planner-capabilities",
        planner_capabilities_handler,
    )
    app.router.add_post("/v1/planner-add", planner_add_handler)
    return app


def main() -> None:
    level_name = os.getenv("LOG_LEVEL", "INFO").upper()
    level = getattr(logging, level_name, logging.INFO)
    logging.basicConfig(
        level=level,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    config = BridgeConfig.from_env()
    web.run_app(
        create_app(config),
        host=os.getenv("BRIDGE_HOST", "0.0.0.0"),
        port=_env_int("BRIDGE_PORT", 8081, 1, 65535),
        access_log=LOGGER,
    )


if __name__ == "__main__":
    main()
