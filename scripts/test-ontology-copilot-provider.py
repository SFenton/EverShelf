#!/usr/bin/env python3
"""No-network tests for the ontology Copilot Unix-socket provider."""

from __future__ import annotations

import importlib.util
import errno
import hashlib
import io
import json
import os
import shutil
import socket
import subprocess
import sys
import threading
import time
import unittest
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
MODULE_PATH = SCRIPT_DIR / "ontology-copilot-provider.py"
SPEC = importlib.util.spec_from_file_location(
    "ontology_copilot_provider",
    MODULE_PATH,
)
assert SPEC and SPEC.loader
provider = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(provider)


def request(
    model: str = "gemini-3.7-flash",
    role: str = "proposer",
    prompt: str = "Return JSON.",
) -> dict:
    schema = {
        "type": "object",
        "additionalProperties": False,
        "properties": {"ok": {"type": "boolean"}},
    }
    payload = {
        "protocol_version": provider.PROTOCOL_VERSION,
        "request_id": "test-request",
        "role": role,
        "model": model,
        "prompt": prompt,
        "prompt_hash": provider.sha256_text(prompt),
        "schema": schema,
        "schema_hash": provider.sha256_text(provider.stable_json(schema)),
        "input_hash": "a" * 64,
        "priority": "background",
    }
    if (
        model in provider.MODEL_WHITELIST
        and provider.MODEL_WHITELIST[model]["effort"] is not None
    ):
        payload["effort"] = provider.MODEL_WHITELIST[model]["effort"]
    return payload


class FakeCompleted:
    def __init__(self, stdout: str, returncode: int = 0, stderr: str = ""):
        self.stdout = stdout
        self.returncode = returncode
        self.stderr = stderr


def success_runner(*args, **kwargs):
    del args, kwargs
    return FakeCompleted(
        provider.stable_json(
            {
                "type": "assistant.message",
                "content": provider.stable_json({"ok": True}),
            }
        )
        + "\n"
        + provider.stable_json(
            {"type": "usage", "usage": {"input_tokens": 12}}
        )
        + "\n"
    )


class ProviderTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.root = (
            SCRIPT_DIR.parent
            / f".op-{os.getpid()}"
        )
        if cls.root.exists():
            shutil.rmtree(cls.root)
        cls.root.mkdir(mode=0o700)

    @classmethod
    def tearDownClass(cls) -> None:
        shutil.rmtree(cls.root, ignore_errors=True)

    def start_server(self, app, name: str):
        path = self.root / (
            hashlib.sha256(name.encode("utf-8")).hexdigest()[:8]
            + ".sock"
        )
        server = provider.ProviderServer(str(path), app)
        os.chmod(path.parent, 0o750)
        os.chown(path, -1, os.getgid())
        os.chmod(path, 0o660)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.shutdown)
        self.addCleanup(server.server_close)
        self.addCleanup(lambda: path.unlink(missing_ok=True))
        return path

    def exchange(self, path: Path, payload: bytes) -> dict:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            client.settimeout(3)
            client.connect(str(path))
            client.sendall(payload + b"\n")
            response = b""
            while not response.endswith(b"\n"):
                response += client.recv(65536)
        return json.loads(response)

    def test_success_and_hardened_argv(self):
        captured = {}

        def runner(argv, **kwargs):
            captured["argv"] = argv
            captured["kwargs"] = kwargs
            prompt_index = argv.index("--prompt")
            captured["request_prompt"] = argv[prompt_index + 1]
            return success_runner()

        invoker = provider.CopilotInvoker(
            runner=runner,
            work_dir=str(self.root / "empty"),
        )
        app = provider.ProviderApplication(invoker)
        path = self.start_server(app, "success")
        req = request()
        response = self.exchange(
            path,
            provider.stable_json(req).encode(),
        )
        self.assertTrue(response["ok"])
        self.assertEqual(response["plan"], {"ok": True})
        self.assertEqual(
            response["request_hash"],
            provider.sha256_text(provider.stable_json(req)),
        )
        argv = captured["argv"]
        self.assertEqual(argv[0], provider.DEFAULT_COPILOT)
        self.assertIn("--no-custom-instructions", argv)
        self.assertIn("--disable-builtin-mcps", argv)
        self.assertIn("--no-remote-export", argv)
        self.assertIn("--no-auto-update", argv)
        self.assertIn("--available-tools=", argv)
        self.assertIn("--excluded-tools=*", argv)
        self.assertNotIn("--deny-tool=*", argv)
        self.assertFalse(captured["kwargs"]["shell"])
        self.assertNotIn("--effort", argv)
        self.assertIn(req["prompt"], captured["request_prompt"])
        self.assertIn(
            provider.stable_json(req["schema"]),
            captured["request_prompt"],
        )

    def test_socket_group_permission_model(self):
        app = provider.ProviderApplication(
            provider.CopilotInvoker(
                runner=success_runner,
                work_dir=str(self.root / "permissions-work"),
            )
        )
        path = self.start_server(app, "permissions")
        socket_stat = path.stat()
        directory_stat = path.parent.stat()
        self.assertEqual(socket_stat.st_mode & 0o777, 0o660)
        self.assertEqual(directory_stat.st_mode & 0o777, 0o750)
        self.assertEqual(socket_stat.st_gid, os.getgid())
        response = self.exchange(
            path,
            provider.stable_json(request()).encode(),
        )
        self.assertTrue(response["ok"])

    def test_bounded_image_attachment(self):
        attachments = self.root / "attachments"
        attachments.mkdir(mode=0o770)
        image = attachments / "expiry-test.png"
        image.write_bytes(b"\x89PNG\r\n\x1a\nbounded-image")
        captured = {}

        def runner(argv, **kwargs):
            del kwargs
            attachment_index = argv.index("--attachment")
            captured["attachment"] = argv[attachment_index + 1]
            self.assertTrue(Path(captured["attachment"]).is_file())
            return success_runner()

        req = request(model="gemini-3.6-flash")
        req["attachment"] = {
            "name": image.name,
            "mime_type": "image/png",
            "sha256": provider.hashlib.sha256(
                image.read_bytes()
            ).hexdigest(),
        }
        app = provider.ProviderApplication(
            provider.CopilotInvoker(
                runner=runner,
                work_dir=str(self.root / "attachment-work"),
                attachments_dir=str(attachments),
            )
        )
        path = self.start_server(app, "attachment")
        response = self.exchange(
            path,
            provider.stable_json(req).encode(),
        )
        self.assertTrue(response["ok"])
        self.assertEqual(
            captured["attachment"],
            str(image),
        )
        self.assertFalse(image.exists())

    def test_sdk_bridge_stays_warm_across_requests(self):
        bridge = self.root / "fake-sdk-bridge.py"
        starts = self.root / "fake-sdk-bridge-starts.txt"
        bridge.write_text(
            "\n".join([
                "import json, sys",
                f"open({str(starts)!r}, 'a').write('start\\n')",
                "for line in sys.stdin:",
                "    request = json.loads(line)",
                "    print(json.dumps({",
                "        'id': request['id'],",
                "        'ok': True,",
                "        'plan': {'ok': True},",
                "        'usage': {'warm': True},",
                "    }), flush=True)",
            ]),
            encoding="utf-8",
        )
        invoker = provider.CopilotInvoker(
            bridge_command=[sys.executable, str(bridge)],
            work_dir=str(self.root / "sdk-bridge-work"),
        )
        try:
            first, first_usage = invoker.invoke(request())
            second, second_usage = invoker.invoke(request())
        finally:
            invoker.close()
        self.assertEqual(first, {"ok": True})
        self.assertEqual(second, {"ok": True})
        self.assertEqual(first_usage, {"warm": True})
        self.assertEqual(second_usage, {"warm": True})
        self.assertEqual(starts.read_text(encoding="utf-8"), "start\n")

    def test_sdk_bridge_broken_pipe_has_restartable_reason(self):
        class BrokenInput:
            def write(self, _value):
                raise BrokenPipeError("fixture")

            def flush(self):
                return None

            def close(self):
                return None

        class FakeProcess:
            stdin = BrokenInput()
            stdout = io.StringIO("")

            def poll(self):
                return None

            def wait(self, timeout=None):
                del timeout
                return 0

        invoker = provider.CopilotInvoker(
            work_dir=str(self.root / "sdk-bridge-broken-pipe"),
        )
        invoker.ensure_bridge = lambda: FakeProcess()
        with self.assertRaises(provider.ProviderError) as caught:
            invoker.invoke(request())
        self.assertEqual(
            caught.exception.code,
            "copilot_sdk_bridge_broken_pipe",
        )

    def test_sdk_bridge_eof_has_restartable_reason(self):
        bridge = self.root / "fake-sdk-bridge-eof.py"
        bridge.write_text(
            "import sys\n"
            "for _line in sys.stdin:\n"
            "    raise SystemExit(0)\n",
            encoding="utf-8",
        )
        invoker = provider.CopilotInvoker(
            bridge_command=[sys.executable, str(bridge)],
            work_dir=str(self.root / "sdk-bridge-eof-work"),
        )
        try:
            with self.assertRaises(provider.ProviderError) as caught:
                invoker.invoke(request())
        finally:
            invoker.close()
        self.assertEqual(
            caught.exception.code,
            "copilot_sdk_bridge_eof",
        )

    def test_sdk_bridge_restart_failure_has_restartable_reason(self):
        invoker = provider.CopilotInvoker(
            work_dir=str(self.root / "sdk-bridge-restart-failed"),
        )

        def fail_restart():
            raise OSError("fixture")

        invoker.ensure_bridge = fail_restart
        with self.assertRaises(provider.ProviderError) as caught:
            invoker.invoke(request())
        self.assertEqual(
            caught.exception.code,
            "copilot_sdk_bridge_restart_failed",
        )

    def test_interactive_waiter_precedes_queued_background_waiter(self):
        invoker = provider.CopilotInvoker(
            runner=success_runner,
            work_dir=str(self.root / "priority-order-work"),
        )
        active = threading.Event()
        release = threading.Event()
        order = []

        def hold_active():
            with invoker.bridge_turn("background"):
                active.set()
                release.wait(timeout=2)

        def wait_for_turn(priority, label):
            with invoker.bridge_turn(priority):
                order.append(label)

        holder = threading.Thread(target=hold_active)
        background = threading.Thread(
            target=wait_for_turn,
            args=("background", "background"),
        )
        interactive = threading.Thread(
            target=wait_for_turn,
            args=("interactive", "interactive"),
        )
        holder.start()
        self.assertTrue(active.wait(timeout=1))
        background.start()
        time.sleep(0.02)
        interactive.start()
        time.sleep(0.02)
        release.set()
        holder.join(timeout=2)
        background.join(timeout=2)
        interactive.join(timeout=2)
        self.assertEqual(order, ["interactive", "background"])

    def test_node_runtime_preflight_requires_v24(self):
        node = self.root / "node-unsupported"
        node.write_text(
            "#!/bin/sh\nprintf 'v22.22.2\\n'\n",
            encoding="utf-8",
        )
        node.chmod(0o700)
        invoker = provider.CopilotInvoker(
            bridge_command=[str(node), "bridge.mjs"],
            work_dir=str(self.root / "node-unsupported-work"),
        )
        with self.assertRaises(provider.ProviderError) as caught:
            invoker.ensure_bridge()
        self.assertEqual(
            caught.exception.code,
            "node_runtime_unsupported",
        )

    def test_node_runtime_preflight_accepts_v24(self):
        node = self.root / "node-supported"
        node.write_text(
            "#!/bin/sh\n"
            "if [ \"$1\" = \"--version\" ]; then\n"
            "  printf 'v24.4.1\\n'\n"
            "  exit 0\n"
            "fi\n"
            "exit 0\n",
            encoding="utf-8",
        )
        node.chmod(0o700)
        invoker = provider.CopilotInvoker()
        invoker.assert_supported_node([str(node), "bridge.mjs"])

    def test_attachment_hash_mismatch_fails_closed(self):
        attachments = self.root / "attachments-mismatch"
        attachments.mkdir(mode=0o770)
        image = attachments / "expiry-test.jpg"
        image.write_bytes(b"not-the-declared-image")
        req = request(model="gemini-3.6-flash")
        req["attachment"] = {
            "name": image.name,
            "mime_type": "image/jpeg",
            "sha256": "0" * 64,
        }
        app = provider.ProviderApplication(
            provider.CopilotInvoker(
                runner=success_runner,
                work_dir=str(self.root / "attachment-mismatch-work"),
                attachments_dir=str(attachments),
            )
        )
        path = self.start_server(app, "attachment-mismatch")
        response = self.exchange(
            path,
            provider.stable_json(req).encode(),
        )
        self.assertFalse(response["ok"])
        self.assertEqual(response["error"], "attachment_hash_mismatch")

    def test_php_python_request_hash_fixture(self):
        schema = {"additionalProperties": False, "type": "object"}
        raw_prompt = "alpha\u2028beta\u2029gamma"
        prompt = raw_prompt.replace("\u2028", " ").replace("\u2029", " ")
        self.assertEqual(prompt, "alpha beta gamma")
        fixture = {
            "protocol_version": provider.PROTOCOL_VERSION,
            "request_id": "hash-parity",
            "role": "proposer",
            "model": "gemini-3.7-flash",
            "prompt": prompt,
            "prompt_hash": provider.sha256_text(prompt),
            "schema": schema,
            "schema_hash": provider.sha256_text(
                provider.stable_json(schema)
            ),
            "input_hash": "b" * 64,
            "priority": "background",
        }
        self.assertEqual(
            provider.sha256_text(provider.stable_json(fixture)),
            "2a53e81370006133fd67f5a52e6973bc926c64446cb1ca8bf5df9c1d10b922fd",
        )

    def test_malformed_request_json(self):
        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=success_runner)
        )
        path = self.start_server(app, "malformed-request")
        response = self.exchange(path, b"{broken")
        self.assertFalse(response["ok"])
        self.assertEqual(response["error"], "request_malformed_json")

    def test_invalid_priority_is_rejected(self):
        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=success_runner)
        )
        path = self.start_server(app, "invalid-priority")
        req = request()
        req["priority"] = "urgent"
        response = self.exchange(
            path,
            provider.stable_json(req).encode(),
        )
        self.assertFalse(response["ok"])
        self.assertEqual(response["error"], "request_priority_invalid")

    def test_background_rate_exhaustion_does_not_consume_interactive_lane(self):
        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=success_runner),
            rate_per_minute=1,
            interactive_rate_per_minute=1,
        )
        path = self.start_server(app, "separate-rate-lanes")
        background = request()
        first = self.exchange(
            path,
            provider.stable_json(background).encode(),
        )
        second = self.exchange(
            path,
            provider.stable_json(background).encode(),
        )
        interactive = request()
        interactive["request_id"] = "interactive-rate-test"
        interactive["priority"] = "interactive"
        third = self.exchange(
            path,
            provider.stable_json(interactive).encode(),
        )
        self.assertTrue(first["ok"])
        self.assertFalse(second["ok"])
        self.assertEqual(second["error"], "rate_limited")
        self.assertTrue(third["ok"])

    def test_sonnet_uses_exact_effort(self):
        captured = {}

        def runner(argv, **kwargs):
            captured["argv"] = argv
            return success_runner()

        invoker = provider.CopilotInvoker(runner=runner)
        req = request(
            model="claude-sonnet-5",
            role="critic",
        )
        plan, _usage = invoker.invoke(req)
        self.assertEqual(plan, {"ok": True})
        effort_index = captured["argv"].index("--effort")
        self.assertEqual(captured["argv"][effort_index + 1], "high")

    def test_malformed_copilot_jsonl(self):
        invoker = provider.CopilotInvoker(
            runner=lambda *a, **k: FakeCompleted("{broken\n")
        )
        app = provider.ProviderApplication(invoker)
        path = self.start_server(app, "malformed-jsonl")
        response = self.exchange(
            path,
            provider.stable_json(request()).encode(),
        )
        self.assertFalse(response["ok"])
        self.assertEqual(response["error"], "copilot_malformed_jsonl")

    def test_single_json_fence_is_accepted(self):
        fenced = provider.stable_json(
            {
                "type": "assistant.message",
                "data": {
                    "content": '```json\n{"ok":true}\n```',
                },
            }
        )
        invoker = provider.CopilotInvoker(
            runner=lambda *a, **k: FakeCompleted(fenced + "\n")
        )
        plan, _usage = invoker.invoke(request())
        self.assertEqual(plan, {"ok": True})

    def test_json_fence_with_commentary_is_rejected(self):
        for content in (
            'Result:\n```json\n{"ok":true}\n```',
            '```json\n{"ok":true}\n```\nDone.',
            '```json\n{"ok":true}\n```json',
        ):
            with self.subTest(content=content):
                event = provider.stable_json(
                    {
                        "type": "assistant.message",
                        "content": content,
                    }
                )
                invoker = provider.CopilotInvoker(
                    runner=lambda *a, **k: FakeCompleted(event + "\n")
                )
                with self.assertRaises(provider.ProviderError) as caught:
                    invoker.invoke(request())
                self.assertEqual(
                    caught.exception.code,
                    "copilot_plan_invalid_json",
                )

    def test_timeout_has_no_fallback(self):
        calls = []

        def runner(argv, **kwargs):
            calls.append(argv)
            raise subprocess.TimeoutExpired(argv, kwargs["timeout"])

        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=runner)
        )
        path = self.start_server(app, "timeout")
        response = self.exchange(
            path,
            provider.stable_json(request()).encode(),
        )
        self.assertFalse(response["ok"])
        self.assertEqual(response["error"], "copilot_timeout")
        self.assertEqual(len(calls), 1)
        self.assertIn("gemini-3.7-flash", calls[0])

    def test_large_prompt_and_schema_are_bounded_in_argv(self):
        captured = {}

        def runner(argv, **kwargs):
            del kwargs
            captured["argv"] = argv
            return success_runner()

        prompt = "x" * 50000
        invoker = provider.CopilotInvoker(
            runner=runner,
            work_dir=str(self.root / "large-prompt"),
        )
        plan, _usage = invoker.invoke(request(prompt=prompt))
        self.assertEqual(plan, {"ok": True})
        request_prompt = captured["argv"][
            captured["argv"].index("--prompt") + 1
        ]
        self.assertIn(prompt, request_prompt)
        self.assertIn(provider.stable_json(request()["schema"]), request_prompt)
        self.assertLess(
            len(request_prompt.encode("utf-8")),
            provider.MAX_COPILOT_ARGUMENT_BYTES,
        )

    def test_oversized_copilot_argument_fails_before_runner(self):
        calls = 0

        def runner(*args, **kwargs):
            nonlocal calls
            del args, kwargs
            calls += 1
            return success_runner()

        invoker = provider.CopilotInvoker(
            runner=runner,
            work_dir=str(self.root / "oversized-argument"),
        )
        with self.assertRaises(provider.ProviderError) as caught:
            invoker.invoke(
                request(prompt="x" * provider.MAX_PROMPT_BYTES)
            )
        self.assertEqual(
            caught.exception.code,
            "copilot_argument_oversized",
        )
        self.assertEqual(calls, 0)

    def test_e2big_is_classified_without_fallback(self):
        calls = 0

        def runner(*args, **kwargs):
            nonlocal calls
            del args, kwargs
            calls += 1
            raise OSError(errno.E2BIG, "argument list too long")

        invoker = provider.CopilotInvoker(
            runner=runner,
            work_dir=str(self.root / "e2big"),
        )
        with self.assertRaises(provider.ProviderError) as caught:
            invoker.invoke(request())
        self.assertEqual(caught.exception.code, "copilot_argv_too_large")
        self.assertEqual(calls, 1)

    def test_unavailable_socket(self):
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            with self.assertRaises(OSError):
                client.connect(str(self.root / "missing.sock"))

    def test_idle_peer_is_released(self):
        original_timeout = provider.SOCKET_IDLE_TIMEOUT
        provider.SOCKET_IDLE_TIMEOUT = 0.1
        self.addCleanup(
            setattr,
            provider,
            "SOCKET_IDLE_TIMEOUT",
            original_timeout,
        )
        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=success_runner)
        )
        path = self.start_server(app, "idle")
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            client.settimeout(2)
            client.connect(str(path))
            response = b""
            while not response.endswith(b"\n"):
                response += client.recv(65536)
        decoded = json.loads(response)
        self.assertFalse(decoded["ok"])
        self.assertEqual(decoded["error"], "idle_timeout")

    def test_oversize_request(self):
        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=success_runner)
        )
        with self.assertRaises(provider.ProviderError) as caught:
            app.handle(b"x" * (provider.MAX_REQUEST_BYTES + 1))
        self.assertEqual(caught.exception.code, "request_oversized")

    def test_exact_max_response_keeps_newline(self):
        overhead = len(provider.stable_json({"x": ""}).encode("utf-8"))
        response = {
            "x": "y" * (provider.MAX_RESPONSE_BYTES - overhead)
        }
        frame = provider.encode_frame(response)
        self.assertEqual(len(frame), provider.MAX_RESPONSE_BYTES + 1)
        self.assertTrue(frame.endswith(b"\n"))

    def test_unauthorized_model_does_not_invoke(self):
        calls = []

        def runner(*args, **kwargs):
            calls.append((args, kwargs))
            return success_runner()

        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=runner)
        )
        bad = request()
        bad["model"] = "unapproved-model"
        bad["effort"] = "medium"
        with self.assertRaises(provider.ProviderError) as caught:
            app.handle(provider.stable_json(bad).encode())
        self.assertEqual(caught.exception.code, "unauthorized_model")
        self.assertEqual(calls, [])

    def test_concurrency_is_bounded(self):
        lock = threading.Lock()
        active = 0
        maximum = 0

        def runner(*args, **kwargs):
            nonlocal active, maximum
            del args, kwargs
            with lock:
                active += 1
                maximum = max(maximum, active)
            time.sleep(0.1)
            with lock:
                active -= 1
            return success_runner()

        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=runner),
            max_concurrency=2,
            rate_per_minute=20,
        )
        path = self.start_server(app, "concurrency")
        results = []

        def worker(index: int):
            req = request()
            req["request_id"] = f"request-{index}"
            results.append(
                self.exchange(
                    path,
                    provider.stable_json(req).encode(),
                )
            )

        threads = [threading.Thread(target=worker, args=(i,)) for i in range(4)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(len(results), 4)
        self.assertTrue(all(result["ok"] for result in results))
        self.assertLessEqual(maximum, 2)

    def test_rate_limit_is_bounded(self):
        app = provider.ProviderApplication(
            provider.CopilotInvoker(runner=success_runner),
            max_concurrency=1,
            rate_per_minute=1,
        )
        first = app.handle(provider.stable_json(request()).encode())
        self.assertTrue(first["ok"])
        with self.assertRaises(provider.ProviderError) as caught:
            app.handle(provider.stable_json(request()).encode())
        self.assertEqual(caught.exception.code, "rate_limited")


if __name__ == "__main__":
    unittest.main(verbosity=2)
