from __future__ import annotations

from dataclasses import replace
import os
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import aiohttp
from aiohttp.test_utils import TestClient, TestServer

from app import (
    BridgeError,
    BridgeConfig,
    CookidooGateway,
    DETAIL_HYDRATION_POLICY_REASON,
    DETAIL_HYDRATION_POLICY_VERSION,
    GatewayMetadataResult,
    GatewayPolicyDisabledError,
    GatewaySearchResult,
    GatewayResponseError,
    MAX_RECIPE_DESCRIPTIVE_ASSETS,
    MAX_RECIPE_SECONDS,
    MAX_RESPONSE_BYTES,
    METADATA_SCHEMA_VERSION,
    MetadataRequest,
    SearchRequest,
    _allowlisted_recipe,
    _raw_quantity,
    _public_amount_text,
    _validate_redirect,
    _safe_https_url,
    _safe_recipe_from_public,
    _safe_recipe_from_raw,
    _upstream_seconds,
    _validate_upstream_url,
    create_app,
)
from cookidoo_api.exceptions import (
    CookidooAuthException,
    CookidooParseException,
    CookidooRequestException,
)
from cookidoo_api.types import CookidooLocalizationConfig
from yarl import URL


def topology_metrics(
    ingredient_count: int,
    group_count: int,
) -> dict[str, int]:
    return {
        "group_count": group_count,
        "group_title_key_count": 0,
        "group_title_nonempty_count": 0,
        "group_title_length_total": 0,
        "group_title_length_max": 0,
        "ingredient_count": ingredient_count,
        "ingredient_ref_key_count": 0,
        "ingredient_ref_nonempty_count": 0,
        "default_title_key_count": 0,
        "default_title_nonempty_count": 0,
        "unit_ref_key_count": 0,
        "unit_ref_nonempty_count": 0,
        "optional_key_count": 0,
        "optional_true_count": 0,
        "optional_false_count": 0,
        "optional_null_count": ingredient_count,
        "shopping_category_ref_key_count": 0,
        "shopping_category_ref_nonempty_count": 0,
    }


class FakeGateway:
    def __init__(self) -> None:
        self.requests: list[SearchRequest] = []
        self.metadata_requests: list[MetadataRequest] = []

    async def search(self, request: SearchRequest) -> GatewaySearchResult:
        self.requests.append(request)
        return GatewaySearchResult(
            recipes=[
                {
                    "external_id": "r100",
                    "title": "Tomato Soup",
                    "metadata_schema_version": METADATA_SCHEMA_VERSION,
                    "general": {
                        "yield_quantity": 4,
                        "yield_unit": "portions",
                        "active_time_seconds": 600,
                        "total_time_seconds": 1800,
                        "difficulty": "easy",
                        "primary_category": "Soups",
                        "equipment": ["sieve"],
                    },
                    "ingredients": [
                        {
                            "name": "Tomato",
                            "source_quantity": 500,
                            "source_quantity_max": None,
                            "source_unit": "g",
                            "source_amount_text": "500 g",
                            "source_group_index": 0,
                            "source_group_position": 0,
                            "source_group_title": None,
                            "source_ingredient_ref": None,
                            "source_default_title": None,
                            "source_unit_ref": None,
                            "source_optional": None,
                            "source_shopping_category_ref": None,
                        },
                        {
                            "name": "Basil",
                            "source_quantity": None,
                            "source_quantity_max": None,
                            "source_unit": None,
                            "source_amount_text": None,
                            "source_group_index": 0,
                            "source_group_position": 1,
                            "source_group_title": None,
                            "source_ingredient_ref": None,
                            "source_default_title": None,
                            "source_unit_ref": None,
                            "source_optional": None,
                            "source_shopping_category_ref": None,
                        },
                    ],
                    "topology_metrics": topology_metrics(2, 1),
                    "image_url": "https://assets.tmecosys.com/image/upload/r100.jpg",
                    "canonical_url": "https://cookidoo.co.uk/recipes/recipe/en-GB/r100",
                    "locale": request.locale,
                }
            ],
            pages_scanned=1,
            last_page=request.page,
            next_page=request.page + 1,
            last_page_had_raw_hits=True,
        )

    async def metadata(
        self, request: MetadataRequest
    ) -> GatewayMetadataResult:
        self.metadata_requests.append(request)
        outcomes = []
        for external_id in request.external_ids:
            recipe = {
                "external_id": external_id,
                "title": f"Metadata {external_id}",
                "metadata_schema_version": METADATA_SCHEMA_VERSION,
                "general": {
                    "yield_quantity": None,
                    "yield_unit": None,
                    "active_time_seconds": None,
                    "total_time_seconds": None,
                    "difficulty": None,
                    "primary_category": None,
                    "equipment": [],
                },
                "ingredients": [
                    {
                        "name": "chicken thighs, boneless and skinless",
                        "source_quantity": 2,
                        "source_quantity_max": None,
                        "source_unit": "pieces",
                        "source_amount_text": "2 pieces",
                        "source_group_index": 0,
                        "source_group_position": 0,
                        "source_group_title": None,
                        "source_ingredient_ref": None,
                        "source_default_title": None,
                        "source_unit_ref": None,
                        "source_optional": None,
                        "source_shopping_category_ref": None,
                    }
                ],
                "topology_metrics": topology_metrics(1, 1),
                "image_url": (
                    "https://assets.tmecosys.com/image/upload/"
                    f"{external_id}.jpg"
                ),
                "canonical_url": (
                    "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                    f"{external_id}"
                ),
                "locale": request.locale,
            }
            outcomes.append(
                {
                    "external_id": external_id,
                    "status": "succeeded",
                    "recipe": recipe,
                }
            )
        return GatewayMetadataResult(outcomes=outcomes, locale=request.locale)


class HugeGateway(FakeGateway):
    async def search(self, request: SearchRequest) -> GatewaySearchResult:
        result = await super().search(request)
        result.recipes[0]["title"] = "x" * MAX_RESPONSE_BYTES
        return result


class HugeMetadataGateway(FakeGateway):
    async def metadata(
        self, request: MetadataRequest
    ) -> GatewayMetadataResult:
        result = await super().metadata(request)
        result.outcomes[0]["recipe"]["title"] = "x" * MAX_RESPONSE_BYTES
        return result


class FakeCookidoo:
    def __init__(
        self,
        search_hits: list[object] | None = None,
        recipe_locale: str = "en-GB",
    ) -> None:
        self.login_calls = 0
        self.search_calls = 0
        self.detail_calls = 0
        self.search_hits = search_hits
        self.recipe_locale = recipe_locale

    async def login(self) -> None:
        self.login_calls += 1

    def save_cookies(self, path: Path) -> None:
        path.write_text('[{"key":"session","value":"fake"}]', encoding="utf-8")

    def load_cookies(self, _path: Path) -> None:
        return

    async def search_recipes(self, **_kwargs: object) -> object:
        self.search_calls += 1
        recipes = self.search_hits
        if recipes is None:
            recipes = [
                SimpleNamespace(
                    id="r200",
                    name="Unsafe raw title",
                    image="https://assets.tmecosys.com/image/upload/raw.jpg",
                    url=(
                        "https://cookidoo.co.uk/recipes/recipe/"
                        f"{self.recipe_locale}/r200"
                    ),
                )
            ]
        return SimpleNamespace(
            recipes=recipes,
            total=len(recipes),
        )

    async def get_recipe_details(self, _recipe_id: str) -> object:
        self.detail_calls += 1
        return SimpleNamespace(
            id="r200",
            name="Metadata Title",
            ingredients=[
                SimpleNamespace(name="Tomato", description="500 g"),
                SimpleNamespace(name="Basil", description="as needed, chopped"),
            ],
            image="https://assets.tmecosys.com/image/upload/r200.jpg",
            url=(
                "https://cookidoo.co.uk/recipes/recipe/"
                f"{self.recipe_locale}/r200"
            ),
            difficulty="easy",
            categories=[SimpleNamespace(name="Soups", notes="must never escape")],
            utensils=["sieve"],
            active_time=600,
            total_time=1800,
            instructions=["must never escape"],
            notes=["must never escape"],
            nutrition={"calories": 100},
        )


class GuardedRawRecipe(dict[str, object]):
    PROHIBITED = {
        "recipeStepGroups",
        "additionalInformation",
        "nutritionGroups",
        "inCollections",
        "tags",
        "rawPayload",
        "preparation",
    }

    def get(self, key: str, default: object = None) -> object:
        if key in self.PROHIBITED:
            raise AssertionError(f"prohibited field inspected: {key}")
        return super().get(key, default)

    def __getitem__(self, key: str) -> object:
        if key in self.PROHIBITED:
            raise AssertionError(f"prohibited field inspected: {key}")
        return super().__getitem__(key)


class GuardedIngredientGroup(GuardedRawRecipe):
    pass


class FakeRawCookidoo(FakeCookidoo):
    def __init__(self) -> None:
        super().__init__()
        self.raw_detail_calls = 0
        self.localization = CookidooLocalizationConfig(
            country_code="gb",
            language="en-GB",
            url="https://cookidoo.co.uk/foundation/en-GB",
        )
        self.api_endpoint = URL("https://cookidoo.co.uk")

    async def _request_json(self, *_args: object, **_kwargs: object) -> object:
        self.raw_detail_calls += 1
        return GuardedRawRecipe(
            {
                "id": "r200",
                "title": "Metadata Title",
                "difficulty": "easy",
                "categories": [
                    {"id": "soups", "title": "Soups", "subtitle": "prohibited note"}
                ],
                "ingredients": [
                    GuardedRawRecipe(
                        {
                            "id": "ingredient-tomato",
                            "defaultTitle": "Tomato",
                            "primaryNotation": "Tomate",
                            "preparation": "must never be inspected",
                        }
                    ),
                    GuardedRawRecipe(
                        {
                            "id": "ingredient-basil",
                            "defaultTitle": "Basil",
                            "primaryNotation": "Basilikum",
                            "preparation": "must never be inspected",
                        }
                    ),
                ],
                "recipeIngredientGroups": [
                    GuardedIngredientGroup(
                        {
                            "title": "Sauce",
                            "recipeIngredients": [
                                GuardedRawRecipe(
                                    {
                                    "id": "row-i1",
                                    "localId": "ingredient-tomato",
                                    "ingredientNotation": "Tomato",
                                    "ingredient_ref": "ingredient-tomato",
                                    "quantity": {
                                        "value": 500,
                                        "from": None,
                                        "to": None,
                                    },
                                    "unitNotation": "g",
                                    "unit_ref": "unit-g",
                                    "optional": False,
                                    "shoppingCategory_ref": "category-produce",
                                    "preparation": "must never be inspected",
                                }
                                ),
                                GuardedRawRecipe(
                                    {
                                    "id": "row-i2",
                                    "localId": "ingredient-tomato",
                                    "ingredientNotation": "Tomato",
                                    "ingredient_ref": "ingredient-tomato",
                                    "quantity": {
                                        "value": None,
                                        "from": 2,
                                        "to": 3,
                                    },
                                    "unitNotation": "pieces",
                                    "unit_ref": "unit-piece",
                                    "optional": True,
                                    "shoppingCategory_ref": "category-produce",
                                    "preparation": "must never be inspected",
                                }
                                ),
                            ],
                        }
                    ),
                    GuardedIngredientGroup(
                        {
                            "title": "Empty section",
                            "recipeIngredients": [],
                        }
                    ),
                    GuardedIngredientGroup(
                        {
                            "title": "",
                            "recipeIngredients": [
                                GuardedRawRecipe(
                                    {
                                    "id": "row-i3",
                                    "localId": "ingredient-basil",
                                    "ingredientNotation": "Basil",
                                    "ingredient_ref": "ingredient-basil",
                                    "quantity": None,
                                    "unitNotation": None,
                                    "unit_ref": None,
                                    "optional": None,
                                    "shoppingCategory_ref": None,
                                    "preparation": "must never be inspected",
                                }
                                ),
                            ],
                        }
                    ),
                ],
                "recipeUtensils": [{"utensilNotation": "sieve"}],
                "servingSize": {
                    "quantity": {"value": 4, "from": None, "to": None},
                    "unitNotation": "portions",
                },
                "times": [
                    {
                        "quantity": {"value": 600, "from": None, "to": None},
                        "type": "activeTime",
                        "comment": "",
                    },
                    {
                        "quantity": {"value": 1800, "from": None, "to": None},
                        "type": "totalTime",
                        "comment": "",
                    },
                ],
                "descriptiveAssets": [
                    {
                        "square": "https://assets.tmecosys.com/image/upload/{transformation}/r200.jpg",
                        "portrait": None,
                        "landscape": None,
                    }
                ],
                "recipeStepGroups": [{"recipeSteps": [{"formattedText": "prohibited"}]}],
                "additionalInformation": [{"content": "prohibited"}],
                "nutritionGroups": [{"name": "prohibited"}],
                "inCollections": [{"title": "prohibited"}],
                "tags": ["prohibited"],
                "rawPayload": {"prohibited": True},
            }
        )

    async def get_recipe_details(self, _recipe_id: str) -> object:
        raise AssertionError("raw adapter must not make an additional detail request")


class OutcomeCookidoo(FakeRawCookidoo):
    def __init__(self) -> None:
        super().__init__()
        self.requested_ids: list[str] = []

    @staticmethod
    def _request_error(url: URL, status: int) -> CookidooRequestException:
        cause = aiohttp.ClientResponseError(
            SimpleNamespace(real_url=url),
            (),
            status=status,
        )
        error = CookidooRequestException("bounded request failure")
        error.__cause__ = cause
        return error

    async def _request_json(self, _method: str, url: URL, *_args: object) -> object:
        recipe_id = url.name
        self.requested_ids.append(recipe_id)
        if recipe_id == "missing":
            raise self._request_error(url, 404)
        if recipe_id == "parse":
            raise CookidooParseException("bounded parse failure")
        if recipe_id == "transient":
            raise self._request_error(url, 503)
        if recipe_id == "auth":
            raise CookidooAuthException("bounded auth failure")
        return GuardedRawRecipe(
            {
                "id": recipe_id,
                "title": f"Metadata {recipe_id}",
                "recipeIngredientGroups": [
                    {
                        "recipeIngredients": [
                            {
                                "ingredientNotation": "Ingredient",
                                "quantity": None,
                                "unitNotation": None,
                            }
                        ]
                    }
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": [],
            }
        )


class FlatIngredientGroupsCookidoo(FakeRawCookidoo):
    async def _request_json(
        self, _method: str, url: URL, *_args: object
    ) -> object:
        recipe_id = url.name
        return GuardedRawRecipe(
            {
                "id": recipe_id,
                "title": "Flat Shopping Shape",
                "recipeIngredientGroups": [
                    {
                        "ingredientNotation": "Tomato",
                        "quantity": {"value": 1, "from": None, "to": None},
                        "unitNotation": "piece",
                    }
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": [],
            }
        )


class MismatchedLocaleCookidoo(FakeCookidoo):
    async def get_recipe_details(self, recipe_id: str) -> object:
        details = await super().get_recipe_details(recipe_id)
        details.id = recipe_id
        details.url = (
            "https://cookidoo.co.uk/recipes/recipe/en-US/"
            f"{recipe_id}"
        )
        return details


class BridgeTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self) -> None:
        self.runtime = Path.cwd() / f".bridge-test-runtime-{os.getpid()}"
        self.runtime.mkdir(exist_ok=True)
        self.config = BridgeConfig(
            bridge_token="test-token",
            default_locale="en-GB",
            cookie_path=self.runtime / "cookies.json",
            request_timeout_seconds=5,
            upstream_timeout_seconds=3,
            rate_limit_per_minute=10,
            max_concurrency=1,
            detail_concurrency=1,
            max_results=10,
        )

    def tearDown(self) -> None:
        for path in sorted(self.runtime.glob("*")):
            path.unlink()
        self.runtime.rmdir()

    async def test_health_and_capabilities_report_policy_disablement(
        self,
    ) -> None:
        async with TestClient(
            TestServer(create_app(self.config, FakeGateway()))
        ) as client:
            health = await (await client.get("/health")).json()
            capabilities = await (
                await client.get("/v1/capabilities")
            ).json()
        self.assertEqual(health["capabilities"], capabilities)
        self.assertEqual(
            capabilities,
            {
                "detail_hydration": False,
                "metadata_hydration": False,
                "ingredient_aware_discovery": False,
                "reason": DETAIL_HYDRATION_POLICY_REASON,
                "policy_version": DETAIL_HYDRATION_POLICY_VERSION,
            },
        )

    async def test_search_requires_bearer_and_rejects_unknown_fields(self) -> None:
        gateway = FakeGateway()
        async with TestClient(TestServer(create_app(self.config, gateway))) as client:
            unauthorized = await client.post(
                "/v1/search", json={"query": "tomato"}
            )
            self.assertEqual(unauthorized.status, 401)

            invalid = await client.post(
                "/v1/search",
                headers={"Authorization": "Bearer test-token"},
                json={"query": "tomato", "instructions": True},
            )
            self.assertEqual(invalid.status, 400)
            self.assertEqual((await invalid.json())["error"], "invalid_request")
        self.assertEqual(gateway.requests, [])

    async def test_search_returns_only_allowlisted_metadata(self) -> None:
        gateway = FakeGateway()
        async with TestClient(TestServer(create_app(self.config, gateway))) as client:
            response = await client.post(
                "/v1/search",
                headers={"Authorization": "Bearer test-token"},
                json={
                    "query": "tomato",
                    "ingredients": ["Basil"],
                    "exclude_ingredients": ["Cream"],
                    "locale": "en-gb",
                    "limit": 2,
                },
            )
            self.assertEqual(response.status, 503)
            body = await response.json()
        self.assertEqual(
            body["error"],
            "metadata_hydration_disabled_policy",
        )
        self.assertEqual(gateway.requests, [])

    async def test_search_response_size_is_bounded(self) -> None:
        async with TestClient(
            TestServer(create_app(self.config, HugeGateway()))
        ) as client:
            response = await client.post(
                "/v1/search",
                headers={"Authorization": "Bearer test-token"},
                json={"query": "tomato", "limit": 1},
            )
            self.assertEqual(response.status, 503)
            self.assertEqual(
                (await response.json())["error"],
                "metadata_hydration_disabled_policy",
            )

    async def test_search_uses_default_locale_and_accepts_script_subtags(self) -> None:
        gateway = FakeGateway()
        async with TestClient(TestServer(create_app(self.config, gateway))) as client:
            default_response = await client.post(
                "/v1/search",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"query": "tomato", "limit": 1},
            )
            self.assertEqual(default_response.status, 503)

            script_response = await client.post(
                "/v1/search",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"query": "tomato", "locale": "zh-Hans", "limit": 1},
            )
            self.assertEqual(script_response.status, 503)

        self.assertEqual(gateway.requests, [])

    async def test_metadata_requires_auth_bounds_ids_and_allowlists_output(
        self,
    ) -> None:
        gateway = FakeGateway()
        async with TestClient(TestServer(create_app(self.config, gateway))) as client:
            unauthorized = await client.post(
                "/v1/metadata",
                json={"locale": "en-GB", "external_ids": ["r1"]},
            )
            self.assertEqual(unauthorized.status, 401)

            missing_locale = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"external_ids": ["r1"]},
            )
            self.assertEqual(missing_locale.status, 400)

            for external_ids in ([], [f"r{index}" for index in range(21)]):
                invalid = await client.post(
                    "/v1/metadata",
                    headers={"Authorization": "Bearer " + "test-token"},
                    json={"locale": "en-GB", "external_ids": external_ids},
                )
                self.assertEqual(invalid.status, 400)

            duplicate = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"locale": "en-GB", "external_ids": ["r1", "r1"]},
            )
            self.assertEqual(duplicate.status, 400)

            prohibited = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={
                    "locale": "en-GB",
                    "external_ids": ["r1"],
                    "instructions": True,
                },
            )
            self.assertEqual(prohibited.status, 400)

            response = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"locale": "en-gb", "external_ids": ["r1", "r2"]},
            )
            self.assertEqual(response.status, 503)
            body = await response.json()

        self.assertEqual(
            body["error"],
            "metadata_hydration_disabled_policy",
        )
        self.assertEqual(gateway.metadata_requests, [])

    async def test_metadata_rate_limit_fails_the_whole_batch(self) -> None:
        gateway = FakeGateway()
        config = replace(self.config, rate_limit_per_minute=1)
        async with TestClient(TestServer(create_app(config, gateway))) as client:
            first = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"locale": "en-GB", "external_ids": ["r1"]},
            )
            limited = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"locale": "en-GB", "external_ids": ["r2"]},
            )
            limited_body = await limited.json()

        self.assertEqual(first.status, 503)
        self.assertEqual(limited.status, 429)
        self.assertEqual(limited_body["error"], "rate_limited")
        self.assertEqual(gateway.metadata_requests, [])

    async def test_metadata_response_size_is_bounded(self) -> None:
        async with TestClient(
            TestServer(create_app(self.config, HugeMetadataGateway()))
        ) as client:
            response = await client.post(
                "/v1/metadata",
                headers={"Authorization": "Bearer " + "test-token"},
                json={"locale": "en-GB", "external_ids": ["r1"]},
            )
            body = await response.json()
        self.assertEqual(response.status, 503)
        self.assertEqual(body["error"], "metadata_hydration_disabled_policy")

    async def test_metadata_preserves_order_and_bounds_per_id_failures(
        self,
    ) -> None:
        fake_client = OutcomeCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True

        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.metadata(
                MetadataRequest(
                    locale="en-GB",
                    external_ids=("ok-1", "missing", "parse", "ok-2"),
                )
            )
        self.assertEqual(fake_client.requested_ids, [])
        self.assertEqual(fake_client.login_calls, 0)

    async def test_metadata_transient_and_auth_errors_fail_the_batch(
        self,
    ) -> None:
        transient_client = OutcomeCookidoo()
        transient_gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [transient_client.localization],
            client_factory=lambda _session, _cfg: transient_client,
        )
        transient_gateway._cookies_loaded = True
        transient_gateway._cookie_available = True
        with self.assertRaises(GatewayPolicyDisabledError):
            await transient_gateway.metadata(
                MetadataRequest(
                    locale="en-GB",
                    external_ids=("ok-1", "transient"),
                )
            )

        auth_client = OutcomeCookidoo()
        auth_gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [auth_client.localization],
            client_factory=lambda _session, _cfg: auth_client,
        )
        auth_gateway._cookies_loaded = True
        auth_gateway._cookie_available = True
        with patch.dict(
            os.environ,
            {
                "COOKIDOO_EMAIL": "operator@example.test",
                "COOKIDOO_PASSWORD": "not-a-real-password",
            },
        ):
            with self.assertRaises(GatewayPolicyDisabledError):
                await auth_gateway.metadata(
                    MetadataRequest(
                        locale="en-GB",
                        external_ids=("ok-1", "auth"),
                    )
                )
        self.assertEqual(auth_client.login_calls, 0)
        self.assertEqual(auth_client.requested_ids, [])

    async def test_direct_metadata_requires_exact_supported_locale(
        self,
    ) -> None:
        fake_client = OutcomeCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        for locale in ("en-US", "en"):
            with self.subTest(locale=locale):
                with self.assertRaises(GatewayPolicyDisabledError) as raised:
                    await gateway.metadata(
                        MetadataRequest(locale=locale, external_ids=("r1",))
                    )
                self.assertEqual(raised.exception.status, 503)
                self.assertEqual(
                    raised.exception.code,
                    "metadata_hydration_disabled_policy",
                )
        self.assertEqual(fake_client.requested_ids, [])

    async def test_direct_metadata_rejects_mismatched_canonical_locale(
        self,
    ) -> None:
        fake_client = MismatchedLocaleCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True
        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.metadata(
                MetadataRequest(locale="en-GB", external_ids=("r200",))
            )
        self.assertEqual(fake_client.detail_calls, 0)
        self.assertEqual(fake_client.login_calls, 0)

    async def test_direct_metadata_rejects_flat_shopping_group_shape(
        self,
    ) -> None:
        fake_client = FlatIngredientGroupsCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [fake_client.localization],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True
        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.metadata(
                MetadataRequest(
                    locale="en-GB",
                    external_ids=("flat-shape",),
                )
            )
        self.assertEqual(fake_client.raw_detail_calls, 0)

    def test_cookidoo_host_allowlist_rejects_lookalikes(self) -> None:
        self.assertEqual(
            _safe_https_url(
                "https://cookidoo.thermomix.com/recipes/recipe/en-US/r1",
                "canonical_url",
                True,
            ),
            "https://cookidoo.thermomix.com/recipes/recipe/en-US/r1",
        )
        self.assertEqual(
            _safe_https_url(
                "https://cookidoo.international/recipes/recipe/en/r2",
                "canonical_url",
                True,
            ),
            "https://cookidoo.international/recipes/recipe/en/r2",
        )
        with self.assertRaises(GatewayResponseError):
            _safe_https_url(
                "https://cookidoo.evil.com/recipes/r3",
                "canonical_url",
                True,
            )
        _validate_upstream_url(URL("https://ciam.prod.cookidoo.vorwerk-digital.com/login"))
        _validate_upstream_url(URL("https://eu.login.vorwerk.com/oauth2/authorize"))
        with self.assertRaises(GatewayResponseError):
            _validate_upstream_url(URL("http://127.0.0.1/internal"))
        with self.assertRaises(GatewayResponseError):
            _validate_upstream_url(URL("https://example.invalid/redirect"))

    def test_recipe_duration_bounds_support_long_processes(self) -> None:
        self.assertEqual(_upstream_seconds(2_682_000), 2_682_000)
        self.assertEqual(_upstream_seconds(MAX_RECIPE_SECONDS), 31_622_400)
        with self.assertRaises(GatewayResponseError):
            _upstream_seconds(MAX_RECIPE_SECONDS + 1)
        for invalid in (-1, 1.5, "1200", True):
            with self.subTest(invalid=invalid):
                with self.assertRaises(GatewayResponseError):
                    _upstream_seconds(invalid)

    def test_public_amount_text_uses_closed_grammar(self) -> None:
        for valid in (
            "1",
            "1/2 tsp",
            "500 g",
            "2 - 3 pieces",
            "1 1/2 cups",
            "½ tbsp",
        ):
            with self.subTest(valid=valid):
                self.assertEqual(_public_amount_text(valid), valid)
        for prose in (
            "1 peeled onion",
            "500 g flour sifted",
            "3 chicken breasts skin removed",
            "2 pieces chopped",
            "as needed",
        ):
            with self.subTest(prose=prose):
                self.assertIsNone(_public_amount_text(prose))

    async def test_redirect_validation_blocks_cross_host_targets(self) -> None:
        params = SimpleNamespace(
            response=SimpleNamespace(headers={"Location": "https://example.invalid/internal"}),
            url=URL("https://cookidoo.co.uk/profile/en-GB/login"),
        )
        with self.assertRaises(GatewayResponseError):
            await _validate_redirect(SimpleNamespace(), SimpleNamespace(), params)

    async def test_gateway_public_fixture_returns_partial_allowlisted_metadata(self) -> None:
        fake_client = FakeCookidoo()
        details = await fake_client.get_recipe_details("r200")
        safe = _safe_recipe_from_public(details)
        self.assertEqual(fake_client.detail_calls, 1)
        self.assertEqual(
            [item.name for item in safe.ingredients],
            ["Tomato", "Basil"],
        )
        self.assertEqual(
            [item.source_amount_text for item in safe.ingredients],
            ["500 g", None],
        )
        self.assertEqual(
            [
                (item.source_group_index, item.source_group_position)
                for item in safe.ingredients
            ],
            [(0, 0), (0, 1)],
        )
        self.assertEqual(
            [
                (
                    item.source_group_title,
                    item.source_ingredient_ref,
                    item.source_default_title,
                    item.source_unit_ref,
                    item.source_optional,
                    item.source_shopping_category_ref,
                )
                for item in safe.ingredients
            ],
            [(None, None, None, None, None, None)] * 2,
        )
        self.assertEqual(safe.topology_metrics, topology_metrics(2, 1))
        self.assertIsNone(safe.yield_quantity)
        self.assertIsNone(safe.yield_unit)
        self.assertEqual(safe.active_time_seconds, 600)
        self.assertEqual(safe.equipment, ("sieve",))

    async def test_language_only_search_returns_effective_localization_locale(
        self,
    ) -> None:
        fake_client = FakeCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="us",
                    language="en-US",
                    url="https://cookidoo.thermomix.com/foundation/en-US",
                ),
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                ),
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True

        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest("tomato", (), (), "en", "TM6", 1, 0, (), 1)
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)

    async def test_exact_regional_search_preserves_requested_locale(self) -> None:
        fake_client = FakeCookidoo(recipe_locale="en-US")
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                ),
                CookidooLocalizationConfig(
                    country_code="us",
                    language="en-US",
                    url="https://cookidoo.thermomix.com/foundation/en-US",
                ),
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True

        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest("tomato", (), (), "en-US", "TM6", 1, 0, (), 1)
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)

    async def test_regional_search_fallback_returns_effective_locale(self) -> None:
        fake_client = FakeCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True

        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest("tomato", (), (), "en-US", "TM6", 1, 0, (), 1)
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)

    async def test_exact_script_search_preserves_requested_locale(self) -> None:
        fake_client = FakeCookidoo(recipe_locale="zh-Hans")
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="cn",
                    language="zh-Hans",
                    url="https://cookidoo.com.cn/foundation/zh-Hans",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        gateway._cookies_loaded = True
        gateway._cookie_available = True

        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest(
                    "tomato", (), (), "zh-Hans", "TM6", 1, 0, (), 1
                )
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)

    async def test_language_only_search_rejects_unsupported_language(self) -> None:
        fake_client = FakeCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )

        with self.assertRaises(GatewayPolicyDisabledError) as raised:
            await gateway.search(
                SearchRequest("tomato", (), (), "de", "TM6", 1, 0, (), 1)
            )

        self.assertEqual(
            raised.exception.code,
            "metadata_hydration_disabled_policy",
        )
        self.assertEqual(fake_client.search_calls, 0)

    async def test_raw_adapter_preserves_ranges_order_and_repetition_without_extra_request(
        self,
    ) -> None:
        fake_client = FakeRawCookidoo()
        raw = await fake_client._request_json()
        details = _safe_recipe_from_raw(
            raw,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r200",
        )
        recipe = _allowlisted_recipe(
            SimpleNamespace(id="r200", name="", image="", url=""),
            details,
            "en-GB",
        )

        self.assertEqual(fake_client.raw_detail_calls, 1)
        self.assertEqual(fake_client.detail_calls, 0)
        self.assertEqual(
            set(recipe),
            {
                "external_id",
                "title",
                "metadata_schema_version",
                "general",
                "ingredients",
                "topology_metrics",
                "image_url",
                "canonical_url",
                "locale",
            },
        )
        self.assertEqual(
            set(recipe["general"]),
            {
                "yield_quantity",
                "yield_unit",
                "active_time_seconds",
                "total_time_seconds",
                "difficulty",
                "primary_category",
                "equipment",
            },
        )
        self.assertEqual(
            [item["name"] for item in recipe["ingredients"]],
            ["Tomato", "Tomato", "Basil"],
        )
        self.assertEqual(
            recipe["ingredients"][0],
            {
                "name": "Tomato",
                "source_quantity": 500.0,
                "source_quantity_max": None,
                "source_unit": "g",
                "source_amount_text": "500 g",
                "source_group_index": 0,
                "source_group_position": 0,
                "source_group_title": "Sauce",
                "source_ingredient_ref": "ingredient-tomato",
                "source_default_title": "Tomato",
                "source_unit_ref": "unit-g",
                "source_optional": False,
                "source_shopping_category_ref": "category-produce",
            },
        )
        self.assertEqual(
            recipe["ingredients"][1],
            {
                "name": "Tomato",
                "source_quantity": 2.0,
                "source_quantity_max": 3.0,
                "source_unit": "pieces",
                "source_amount_text": "2 - 3 pieces",
                "source_group_index": 0,
                "source_group_position": 1,
                "source_group_title": "Sauce",
                "source_ingredient_ref": "ingredient-tomato",
                "source_default_title": "Tomato",
                "source_unit_ref": "unit-piece",
                "source_optional": True,
                "source_shopping_category_ref": "category-produce",
            },
        )
        self.assertEqual(
            [
                (item["source_group_index"], item["source_group_position"])
                for item in recipe["ingredients"]
            ],
            [(0, 0), (0, 1), (1, 0)],
        )
        self.assertEqual(
            recipe["ingredients"][2],
            {
                "name": "Basil",
                "source_quantity": None,
                "source_quantity_max": None,
                "source_unit": None,
                "source_amount_text": None,
                "source_group_index": 1,
                "source_group_position": 0,
                "source_group_title": None,
                "source_ingredient_ref": "ingredient-basil",
                "source_default_title": "Basil",
                "source_unit_ref": None,
                "source_optional": None,
                "source_shopping_category_ref": None,
            },
        )
        self.assertEqual(
            recipe["topology_metrics"],
            {
                "group_count": 2,
                "group_title_key_count": 2,
                "group_title_nonempty_count": 1,
                "group_title_length_total": 5,
                "group_title_length_max": 5,
                "ingredient_count": 3,
                "ingredient_ref_key_count": 3,
                "ingredient_ref_nonempty_count": 3,
                "default_title_key_count": 3,
                "default_title_nonempty_count": 3,
                "unit_ref_key_count": 3,
                "unit_ref_nonempty_count": 2,
                "optional_key_count": 3,
                "optional_true_count": 1,
                "optional_false_count": 1,
                "optional_null_count": 1,
                "shopping_category_ref_key_count": 3,
                "shopping_category_ref_nonempty_count": 2,
            },
        )
        self.assertEqual(recipe["general"]["yield_quantity"], 4.0)
        self.assertEqual(recipe["general"]["yield_unit"], "portions")
        self.assertEqual(recipe["general"]["primary_category"], "Soups")
        self.assertEqual(recipe["general"]["equipment"], ["sieve"])
        serialized = repr(recipe)
        for prohibited in (
            "recipeStepGroups",
            "formattedText",
            "additionalInformation",
            "nutritionGroups",
            "rawPayload",
            "prohibited note",
            "must never be inspected",
            "primaryNotation",
        ):
            self.assertNotIn(prohibited, serialized)

    def test_raw_topology_catalog_join_and_missing_reference(self) -> None:
        mapped = GuardedRawRecipe(
            {
                "id": "r-topology-map",
                "title": "Mapped Topology",
                "ingredients": {
                    "canonical-id": GuardedRawRecipe(
                        {
                            "defaultTitle": "Ingredient One",
                            "primaryNotation": "Localized One",
                            "preparation": "must never be inspected",
                        }
                    )
                },
                "recipeIngredientGroups": [
                    {
                        "title": "Section",
                        "recipeIngredients": [
                            GuardedRawRecipe(
                                {
                                    "id": "row-ulid",
                                    "localId": "canonical-id",
                                    "ingredient_ref": "canonical-id",
                                    "ingredientNotation": "Localized One",
                                    "quantity": None,
                                    "unitNotation": None,
                                    "preparation": "must never be inspected",
                                }
                            )
                        ],
                    }
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": [],
            }
        )
        safe = _safe_recipe_from_raw(
            mapped,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r-topology-map",
        )
        self.assertEqual(safe.ingredients[0].source_ingredient_ref, "canonical-id")
        self.assertEqual(safe.ingredients[0].source_default_title, "Ingredient One")

        catalog_id_fallback = GuardedRawRecipe(
            {
                **mapped,
                "id": "r-topology-fallback",
                "recipeIngredientGroups": [
                    {
                        "title": "Section",
                        "recipeIngredients": [
                            {
                                "id": "canonical-id",
                                "ingredientNotation": "Localized One",
                                "quantity": None,
                                "unitNotation": None,
                            }
                        ],
                    }
                ],
            }
        )
        safe_fallback = _safe_recipe_from_raw(
            catalog_id_fallback,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r-topology-fallback",
        )
        self.assertEqual(
            safe_fallback.ingredients[0].source_ingredient_ref,
            "canonical-id",
        )
        self.assertEqual(
            safe_fallback.ingredients[0].source_default_title,
            "Ingredient One",
        )

        unknown_row_id = GuardedRawRecipe(
            {
                **mapped,
                "id": "r-topology-row-id",
                "recipeIngredientGroups": [
                    {
                        "title": "Section",
                        "recipeIngredients": [
                            {
                                "id": "row-only-id",
                                "ingredientNotation": "Uncatalogued",
                                "quantity": None,
                                "unitNotation": None,
                            }
                        ],
                    }
                ],
            }
        )
        safe_unknown_row = _safe_recipe_from_raw(
            unknown_row_id,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r-topology-row-id",
        )
        self.assertIsNone(
            safe_unknown_row.ingredients[0].source_ingredient_ref
        )
        self.assertIsNone(
            safe_unknown_row.ingredients[0].source_default_title
        )

        missing = GuardedRawRecipe(
            {
                **mapped,
                "id": "r-topology-missing",
                "ingredients": [],
                "recipeIngredientGroups": [
                    {
                        "title": "",
                        "recipeIngredients": [
                            {
                                "ingredient_ref": "missing-reference",
                                "ingredientNotation": "Still Valid",
                                "quantity": None,
                                "unitNotation": None,
                            }
                        ],
                    }
                ],
            }
        )
        safe_missing = _safe_recipe_from_raw(
            missing,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r-topology-missing",
        )
        self.assertEqual(
            safe_missing.ingredients[0].source_ingredient_ref,
            "missing-reference",
        )
        self.assertIsNone(safe_missing.ingredients[0].source_default_title)

    def test_raw_topology_catalog_coalesces_consistent_duplicates(self) -> None:
        def payload(catalog: list[dict[str, object]]) -> GuardedRawRecipe:
            return GuardedRawRecipe(
                {
                    "id": "r-catalog-duplicates",
                    "title": "Catalog Duplicates",
                    "ingredients": catalog,
                    "recipeIngredientGroups": [
                        {
                            "title": "Section",
                            "recipeIngredients": [
                                {
                                    "ingredient_ref": "canonical-id",
                                    "ingredientNotation": "Localized",
                                    "quantity": None,
                                    "unitNotation": None,
                                }
                            ],
                        }
                    ],
                    "recipeUtensils": [],
                    "servingSize": None,
                    "times": [],
                    "categories": [],
                    "descriptiveAssets": [],
                }
            )

        same_title = _safe_recipe_from_raw(
            payload([
                {
                    "id": "canonical-id",
                    "defaultTitle": "Canonical title",
                },
                {
                    "id": "canonical-id",
                    "defaultTitle": "Canonical title",
                },
            ]),
            (
                "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                "r-catalog-duplicates"
            ),
        )
        self.assertEqual(
            same_title.ingredients[0].source_default_title,
            "Canonical title",
        )

        missing_entry = {"id": "canonical-id"}
        titled_entry = {
            "id": "canonical-id",
            "defaultTitle": "Canonical title",
        }
        for catalog in (
            [missing_entry, titled_entry],
            [titled_entry, missing_entry],
        ):
            with self.subTest(catalog=catalog):
                merged = _safe_recipe_from_raw(
                    payload(catalog),
                    (
                        "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                        "r-catalog-duplicates"
                    ),
                )
                self.assertEqual(
                    merged.ingredients[0].source_default_title,
                    "Canonical title",
                )
                self.assertEqual(
                    merged.topology_metrics["default_title_key_count"],
                    1,
                )

        with self.assertRaises(GatewayResponseError):
            _safe_recipe_from_raw(
                payload([
                    {
                        "id": "canonical-id",
                        "defaultTitle": "First title",
                    },
                    {
                        "id": "canonical-id",
                        "defaultTitle": "Conflicting title",
                    },
                ]),
                (
                    "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                    "r-catalog-duplicates"
                ),
            )

    def test_raw_topology_rejects_malformed_allowed_fields(self) -> None:
        def payload(
            *,
            group_title: object = "Section",
            ingredient: object | None = None,
            catalog: object | None = None,
        ) -> GuardedRawRecipe:
            return GuardedRawRecipe(
                {
                    "id": "r-invalid-topology",
                    "title": "Invalid Topology",
                    "ingredients": [] if catalog is None else catalog,
                    "recipeIngredientGroups": [
                        {
                            "title": group_title,
                            "recipeIngredients": [
                                ingredient
                                if ingredient is not None
                                else {
                                    "ingredientNotation": "Ingredient",
                                    "quantity": None,
                                    "unitNotation": None,
                                }
                            ],
                        }
                    ],
                    "recipeUtensils": [],
                    "servingSize": None,
                    "times": [],
                    "categories": [],
                    "descriptiveAssets": [],
                }
            )

        invalid_payloads = [
            payload(group_title="x" * 161),
            payload(
                ingredient={
                    "ingredientNotation": "Ingredient",
                    "ingredient_ref": "bad reference",
                    "quantity": None,
                    "unitNotation": None,
                }
            ),
            payload(
                ingredient={
                    "id": "row-ulid",
                    "localId": "canonical-two",
                    "ingredient_ref": "canonical-one",
                    "ingredientNotation": "Ingredient",
                    "quantity": None,
                    "unitNotation": None,
                }
            ),
            payload(
                ingredient={
                    "ingredientNotation": "Ingredient",
                    "unit_ref": "bad reference",
                    "quantity": None,
                    "unitNotation": None,
                }
            ),
            payload(
                ingredient={
                    "ingredientNotation": "Ingredient",
                    "shoppingCategory_ref": "bad reference",
                    "quantity": None,
                    "unitNotation": None,
                }
            ),
            payload(
                ingredient={
                    "ingredientNotation": "Ingredient",
                    "optional": "false",
                    "quantity": None,
                    "unitNotation": None,
                }
            ),
            payload(
                catalog={
                    "ingredient-one": {
                        "id": "ingredient-two",
                        "defaultTitle": "Mismatch",
                    }
                }
            ),
            payload(
                catalog=[
                    {
                        "id": "ingredient-one",
                        "defaultTitle": "x" * 201,
                    }
                ]
            ),
        ]
        for invalid in invalid_payloads:
            with self.subTest(invalid=invalid):
                with self.assertRaises(GatewayResponseError):
                    _safe_recipe_from_raw(
                        invalid,
                        (
                            "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                            "r-invalid-topology"
                        ),
                    )

    async def test_direct_metadata_reuses_safe_loader_without_search(self) -> None:
        fake_client = FakeRawCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [fake_client.localization],
            client_factory=lambda _session, _cfg: fake_client,
        )
        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.metadata(
                MetadataRequest(locale="en-GB", external_ids=("r200",))
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.raw_detail_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)
        self.assertEqual(fake_client.login_calls, 0)

    async def test_runtime_search_never_calls_raw_or_public_detail(self) -> None:
        fake_client = FakeRawCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [fake_client.localization],
            client_factory=lambda _session, _cfg: fake_client,
        )
        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest(
                    "tomato",
                    (),
                    (),
                    "en-GB",
                    "TM6",
                    1,
                    0,
                    (),
                    1,
                )
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.raw_detail_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)
        self.assertEqual(fake_client.login_calls, 0)

    def test_raw_adapter_bounds_fields_and_requires_yield_unit(self) -> None:
        self.assertEqual(
            _raw_quantity({"value": 2, "from": None, "to": None}),
            (2.0, None),
        )
        self.assertEqual(
            _raw_quantity({"value": None, "from": 2, "to": 3}),
            (2.0, 3.0),
        )
        for malformed in (
            {"value": None, "from": 2, "to": None},
            {"value": None, "from": None, "to": 3},
            {"value": 2, "from": 2, "to": 3},
            {"value": None, "from": None, "to": None},
        ):
            with self.subTest(malformed=malformed):
                with self.assertRaises(GatewayResponseError):
                    _raw_quantity(malformed)

        for groups_state in ("missing", "null", "empty", "empty_group"):
            raw = {
                "id": "r-groups-policy",
                "title": "Groups Policy",
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": [],
            }
            if groups_state == "null":
                raw["recipeIngredientGroups"] = None
            elif groups_state == "empty":
                raw["recipeIngredientGroups"] = []
            elif groups_state == "empty_group":
                raw["recipeIngredientGroups"] = [
                    {"recipeIngredients": []}
                ]
            with self.subTest(groups_state=groups_state):
                with self.assertRaises(GatewayResponseError):
                    _safe_recipe_from_raw(
                        GuardedRawRecipe(raw),
                        (
                            "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                            "r-groups-policy"
                        ),
                    )

        for public_ingredients in ("missing", None, []):
            public = SimpleNamespace(
                id="r-public-groups-policy",
                name="Public Groups Policy",
            )
            if public_ingredients != "missing":
                public.ingredients = public_ingredients
            with self.subTest(public_ingredients=public_ingredients):
                with self.assertRaises(GatewayResponseError):
                    _safe_recipe_from_public(public)

        partial = GuardedRawRecipe(
            {
                "id": "r300",
                "title": "Partial Metadata",
                "recipeIngredientGroups": [
                    {
                        "recipeIngredients": [
                            {
                                "ingredientNotation": "Ingredient",
                                "quantity": None,
                                "unitNotation": None,
                            }
                        ]
                    }
                ],
                "recipeUtensils": [],
                "servingSize": {
                    "quantity": {"value": 6, "from": None, "to": None},
                    "unitNotation": None,
                },
                "times": [],
                "categories": [],
                "descriptiveAssets": None,
                "recipeStepGroups": [{"recipeSteps": [{"formattedText": "prohibited"}]}],
            }
        )
        safe = _safe_recipe_from_raw(
            partial,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r300",
        )
        self.assertIsNone(safe.yield_quantity)
        self.assertIsNone(safe.yield_unit)

        too_many = GuardedRawRecipe(
            {
                "id": "r301",
                "title": "Too Many Ingredients",
                "recipeIngredientGroups": [
                    {
                        "recipeIngredients": [
                            {
                                "ingredientNotation": f"Ingredient {index}",
                                "quantity": None,
                                "unitNotation": None,
                            }
                            for index in range(201)
                        ]
                    }
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": None,
            }
        )
        with self.assertRaises(GatewayResponseError):
            _safe_recipe_from_raw(
                too_many,
                "https://cookidoo.co.uk/recipes/recipe/en-GB/r301",
            )

        too_many_groups = GuardedRawRecipe(
            {
                "id": "r301-groups",
                "title": "Too Many Groups",
                "recipeIngredientGroups": [
                    {"recipeIngredients": []} for _index in range(41)
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": None,
            }
        )
        with self.assertRaises(GatewayResponseError):
            _safe_recipe_from_raw(
                too_many_groups,
                "https://cookidoo.co.uk/recipes/recipe/en-GB/r301-groups",
            )

        many_assets = GuardedRawRecipe(
            {
                "id": "r301-assets",
                "title": "Many Assets",
                "recipeIngredientGroups": [
                    {
                        "recipeIngredients": [
                            {
                                "ingredientNotation": "Ingredient",
                                "quantity": None,
                                "unitNotation": None,
                            }
                        ]
                    }
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": [
                    {
                        "square": (
                            "https://assets.tmecosys.com/image/upload/"
                            "{transformation}/asset-"
                            f"{index}.jpg"
                        ),
                        "portrait": None,
                        "landscape": None,
                    }
                    for index in range(22)
                ],
            }
        )
        safe_many_assets = _safe_recipe_from_raw(
            many_assets,
            "https://cookidoo.co.uk/recipes/recipe/en-GB/r301-assets",
        )
        self.assertEqual(
            safe_many_assets.image,
            (
                "https://assets.tmecosys.com/image/upload/"
                "t_web_rdp_recipe_584x480_1_5x/asset-0.jpg"
            ),
        )

        too_many_assets = GuardedRawRecipe(
            {
                **many_assets,
                "id": "r301-assets-overflow",
                "descriptiveAssets": [
                    {
                        "square": None,
                        "portrait": None,
                        "landscape": None,
                    }
                    for _index in range(
                        MAX_RECIPE_DESCRIPTIVE_ASSETS + 1
                    )
                ],
            }
        )
        with self.assertRaises(GatewayResponseError):
            _safe_recipe_from_raw(
                too_many_assets,
                (
                    "https://cookidoo.co.uk/recipes/recipe/en-GB/"
                    "r301-assets-overflow"
                ),
            )

        flat_shopping_shape = GuardedRawRecipe(
            {
                "id": "r302",
                "title": "Flat Shopping Shape",
                "recipeIngredientGroups": [
                    {
                        "ingredientNotation": "Tomato",
                        "quantity": {"value": 1, "from": None, "to": None},
                        "unitNotation": "piece",
                    }
                ],
                "recipeUtensils": [],
                "servingSize": None,
                "times": [],
                "categories": [],
                "descriptiveAssets": None,
            }
        )
        with self.assertRaises(GatewayResponseError):
            _safe_recipe_from_raw(
                flat_shopping_shape,
                "https://cookidoo.co.uk/recipes/recipe/en-GB/r302",
            )

    async def test_excluded_only_page_still_reports_forward_progress(self) -> None:
        fake_client = FakeCookidoo()
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest("tomato", (), (), "en-GB", "TM6", 1, 7, ("r200",), 1)
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)

    async def test_empty_page_reports_stop_progress(self) -> None:
        fake_client = FakeCookidoo(search_hits=[])
        gateway = CookidooGateway(
            SimpleNamespace(),
            self.config,
            [
                CookidooLocalizationConfig(
                    country_code="gb",
                    language="en-GB",
                    url="https://cookidoo.co.uk/foundation/en-GB",
                )
            ],
            client_factory=lambda _session, _cfg: fake_client,
        )
        with self.assertRaises(GatewayPolicyDisabledError):
            await gateway.search(
                SearchRequest("tomato", (), (), "en-GB", "TM6", 1, 11, (), 1)
            )
        self.assertEqual(fake_client.search_calls, 0)
        self.assertEqual(fake_client.detail_calls, 0)


if __name__ == "__main__":
    unittest.main()
