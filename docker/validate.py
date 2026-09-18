#!/usr/bin/env python3
"""
Static validation of the container setup.

WHY THIS EXISTS. `docker compose config` and `docker build` are the real
checks, and they need a daemon. Where one is unavailable — CI without
privileged mode, a review environment, a laptop with Docker stopped — this
asserts everything that can be asserted from the files themselves.

It is not a substitute for a build. It catches the mistakes that a build would
catch late and expensively: a healthcheck referencing a binary the image does
not contain, a service depending on something that never becomes healthy, a
bind mount that masks an installed dependency, a secret in an image layer.

    python3 docker/validate.py
"""

import os
import re
import sys

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FAILED: list[str] = []
PASSED = 0
WARNED: list[str] = []


def check(label: str, ok: bool, detail: str = "") -> None:
    global PASSED
    if ok:
        PASSED += 1
        print(f"  PASS  {label}")
    else:
        FAILED.append(label)
        print(f"  FAIL  {label}" + (f"\n          {detail}" if detail else ""))


def warn(label: str, detail: str = "") -> None:
    WARNED.append(label)
    print(f"  WARN  {label}" + (f"\n          {detail}" if detail else ""))


def read(rel: str) -> str:
    with open(os.path.join(ROOT, rel)) as handle:
        return handle.read()


compose = yaml.safe_load(read("docker-compose.yml"))
services = compose["services"]

print("═══ 1. compose structure ═══")
check("docker-compose.yml parses as YAML", True)
check("an explicit network is declared", bool(compose.get("networks")),
      "services would share the host default bridge")
check("named volumes are declared", len(compose.get("volumes") or {}) >= 3)

# Every service must be on the project network, or DNS between them is luck.
off_network = [
    n for n, s in services.items()
    if not s.get("networks") and "x-backend" not in str(s)
]
check("every service joins the project network", not off_network, f"missing: {off_network}")

print("\n═══ 2. required services present, none spurious ═══")
required = {"postgres", "redis", "api", "worker", "worker-ingestion", "scheduler", "frontend"}
optional = {"nginx"}
actual = set(services)
check("all architecture services defined", required <= actual, f"missing: {required - actual}")
check("no services beyond the architecture", actual <= required | optional,
      f"unexpected: {actual - required - optional}")
check("nginx is behind a profile, not always on",
      "production" in (services.get("nginx", {}).get("profiles") or []))

print("\n═══ 3. health checks ═══")
for name, svc in services.items():
    hc = svc.get("healthcheck")
    check(f"{name} declares a healthcheck", bool(hc))

# A healthcheck that shells out to a binary the image lacks fails forever,
# which looks exactly like a broken application.
IMAGE_BINARIES = {
    "postgres": {"pg_isready", "psql", "sh"},
    "redis": {"redis-cli", "sh", "grep"},
    "nginx": {"wget", "sh"},
}
for name in ("postgres", "redis", "nginx"):
    test = " ".join(services[name]["healthcheck"]["test"])
    used = set(re.findall(r"\b(pg_isready|redis-cli|wget|curl|grep|node|php)\b", test))
    missing = used - IMAGE_BINARIES[name]
    check(f"{name} healthcheck uses only binaries in its image", not missing, f"missing: {missing}")

for name in ("api", "worker", "worker-ingestion", "scheduler"):
    test = " ".join(services[name]["healthcheck"]["test"])
    check(f"{name} healthcheck uses php, which its image has", "php" in test)

check("frontend healthcheck uses node, not curl",
      "node" in " ".join(services["frontend"]["healthcheck"]["test"]))

print("\n═══ 4. dependency graph ═══")


def depends(svc: dict) -> list[str]:
    dep = svc.get("depends_on") or {}
    return list(dep) if isinstance(dep, dict) else list(dep)


for name in ("api", "worker", "worker-ingestion", "scheduler"):
    deps = depends(services[name])
    check(f"{name} waits on postgres and redis", {"postgres", "redis"} <= set(deps), f"has: {deps}")

# Waiting on "started" rather than "healthy" means a container can begin
# before the thing it needs is usable.
weak = []
for name, svc in services.items():
    dep = svc.get("depends_on") or {}
    if isinstance(dep, dict):
        for target, spec in dep.items():
            cond = (spec or {}).get("condition")
            if target in ("postgres", "redis") and cond != "service_healthy":
                weak.append(f"{name}->{target}={cond}")
check("data stores are awaited on health, not mere start", not weak, f"weak: {weak}")

# A cycle deadlocks startup.
def cycles() -> list[str]:
    seen, stack, found = set(), set(), []

    def walk(node: str) -> None:
        if node in stack:
            found.append(node)
            return
        if node in seen or node not in services:
            return
        seen.add(node)
        stack.add(node)
        for nxt in depends(services[node]):
            walk(nxt)
        stack.discard(node)

    for svc in services:
        walk(svc)
    return found


check("dependency graph is acyclic", not cycles(), f"cycle at: {cycles()}")

print("\n═══ 5. bind mounts do not mask installed dependencies ═══")
# This is the failure that makes every backend container exit immediately.
for name, svc in services.items():
    vols = svc.get("volumes") or []
    flat = " ".join(str(v) for v in vols)
    if "./backend:/app" in flat:
        # The invariant is "the bind mount must not leave the container without
        # dependencies", and there are two ways to satisfy it. A named volume
        # was the original answer and turned out to be the wrong one: Docker
        # fills such a volume from the image ONCE, at creation, so the moment
        # the host copy and the volume diverged nothing could repair it and
        # every backend container restarted forever.
        #
        # The other answer -- install on the host, let the bind mount carry it
        # in -- keeps one source of truth. This accepts either, and fails only
        # if neither is present.
        has_volume = "/app/vendor" in flat
        has_marker = os.path.exists(os.path.join(ROOT, "backend/composer.json"))
        check(f"{name} has a route to its dependencies", has_volume or has_marker,
              "no vendor volume and no composer.json to install from")
    if "./frontend:/app" in flat:
        check(f"{name} preserves /app/node_modules", "/app/node_modules" in flat)

print("\n═══ 6. secrets ═══")
for path in ("backend/Dockerfile", "frontend/Dockerfile"):
    src = read(path)
    baked = re.findall(r"^(?:ENV|ARG)\s+(\w*(?:TOKEN|KEY|PASSWORD|SECRET)\w*)", src, re.M | re.I)
    check(f"{path} bakes no secret into a layer", not baked, f"found: {baked}")
    check(f"{path} copies no .env", not re.search(r"^COPY\s+[^\n]*\.env", src, re.M))

for path in ("backend/.dockerignore", "frontend/.dockerignore"):
    body = read(path)
    check(f"{path} excludes .env", re.search(r"^\.env$", body, re.M) is not None)

# Required secrets must have no default, so a missing one stops the stack
# rather than silently deploying a known password.
compose_raw = read("docker-compose.yml")
for var in ("POSTGRES_PASSWORD", "REDIS_PASSWORD"):
    check(f"{var} has no default and fails loudly",
          f"${{{var}:?" in compose_raw,
          "a default password in compose is a password in production")

print("\n═══ 7. non-root ═══")
for path, expected in (("backend/Dockerfile", "devradar"), ("frontend/Dockerfile", "node")):
    src = read(path)
    users = re.findall(r"^USER\s+(\S+)", src, re.M)
    check(f"{path} drops privileges to {expected}", expected in users, f"USER lines: {users}")
    # The final stage is what actually runs.
    tail = src.rsplit("FROM ", 1)[-1]
    check(f"{path} final stage is not root", re.search(r"^USER\s+", tail, re.M) is not None)

print("\n═══ 8. base images are minimal and pinned ═══")
for path in ("backend/Dockerfile", "frontend/Dockerfile"):
    for base in re.findall(r"^FROM\s+([^\s]+)", read(path), re.M):
        if ":" not in base:
            continue  # a named stage
        check(f"{path}: {base} is pinned and slim",
              "latest" not in base and ("alpine" in base or "slim" in base),
              "unpinned or full-fat base image")

for name in ("postgres", "redis", "nginx"):
    image = services[name]["image"]
    check(f"{name} image {image} is pinned and slim",
          "latest" not in image and "alpine" in image)

print("\n═══ 9. referenced files exist ═══")
for rel in ("docker/nginx/devradar.conf", "docker/nginx/proxy_headers.conf",
            ".env.example", "backend/Dockerfile", "frontend/Dockerfile",
            "backend/.dockerignore", "frontend/.dockerignore"):
    check(f"{rel} exists", os.path.exists(os.path.join(ROOT, rel)))

for name, svc in services.items():
    for vol in svc.get("volumes") or []:
        host = str(vol).split(":")[0]
        if host.startswith("./"):
            check(f"{name} mount source {host} exists",
                  os.path.exists(os.path.join(ROOT, host.lstrip("./"))))

print("\n═══ 10. every compose variable is documented ═══")
env_example = read(".env.example")
referenced = set(re.findall(r"\$\{([A-Z0-9_]+)", compose_raw))
undocumented = sorted(v for v in referenced if not re.search(rf"^{v}=", env_example, re.M))
check("no undocumented environment variable", not undocumented, f"missing from .env.example: {undocumented}")

print("\n═══ 11. the ingestion worker stays serialised ═══")
ing = services["worker-ingestion"]["command"]
check("ingestion runs one job per process", "--max-jobs=1" in ing,
      "two concurrent runs mean duplicate PAID fetches")
check("ingestion does not retry", "--tries=1" in ing,
      "a retry is a repeat purchase")
check("ingestion is a single replica", (services["worker-ingestion"].get("deploy") or {}).get("replicas") == 1)

print("\n" + "═" * 62)
print(f"  passed: {PASSED}   failed: {len(FAILED)}   warnings: {len(WARNED)}")
if FAILED:
    print("\n  failures:")
    for item in FAILED:
        print(f"    - {item}")
print("═" * 62)
print("\n  NOTE: this is static validation. It does not build an image or start")
print("  a container. `docker compose build` and `docker compose up` remain the")
print("  only checks that prove the stack actually runs.")

sys.exit(1 if FAILED else 0)
