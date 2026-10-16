"""HTTP contract measurement against disposable files/process stubs only."""
import json
from pathlib import Path
import statistics
import sys
import tempfile
import threading
import time
import urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from gameserver_manager.core import GameServerManager, atomic_json
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import Principal
from gameserver_manager.server import LocalServer
from test_manager import FakeBridge
from test_profiles import add_aska


def main():
    with tempfile.TemporaryDirectory(prefix="gameserver-benchmark-") as temporary:
        root = Path(temporary)
        install = root / "Enshrouded Server"
        install.mkdir()
        (install / "enshrouded_server.exe").touch()
        atomic_json(root / "manager.local.json", {"server_directory": str(install)})
        bridge = FakeBridge()
        manager = GameServerManager(root, bridge)
        manager.setup()
        registry = ServerRegistry(root, manager)
        add_aska(registry, root)
        entered, release = threading.Event(), threading.Event()
        original = bridge.call

        def blocked(action):
            if action == "Start":
                entered.set()
                release.wait(10)
            return original(action)

        bridge.call = blocked
        with LocalServer(registry, 0) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                measurements = {}

                def measure(label, path):
                    timings, sizes = [], []
                    for _ in range(30):
                        started = time.perf_counter()
                        with urllib.request.urlopen(f"http://127.0.0.1:{server.server_port}" + path, timeout=2) as response:
                            sizes.append(len(response.read()))
                        timings.append((time.perf_counter() - started) * 1000)
                    measurements[label] = {"median_ms": round(statistics.median(timings), 3),
                                           "p95_ms": round(sorted(timings)[28], 3), "bytes": int(statistics.median(sizes))}

                measure("legacy_owner_state", "/api/state?server=enshrouded")
                measure("separate_status", "/api/status?server=enshrouded")
                _job, future = server.app.submit(Principal("benchmark", "owner"), "start", {"server_id": "enshrouded"})
                if not entered.wait(2):
                    raise RuntimeError("Test job did not start")
                measure("same_instance_during_job", "/api/status?server=enshrouded")
                measure("other_instance_during_job", "/api/status?server=aska")
                release.set()
                future.result(timeout=2)
                print(json.dumps({"samples_per_case": 30, "process_source": "FakeBridge; no real Windows/game load", **measurements}, indent=2))
            finally:
                release.set()
                server.shutdown()
                thread.join(timeout=2)


if __name__ == "__main__":
    main()
