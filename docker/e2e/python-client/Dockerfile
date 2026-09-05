FROM python:3.12-alpine

# twine is pinned forward, not to "whatever was current when this was written": the
# fixture must stay free to build whatever a currently-released `setuptools` produces,
# and this suite exists to catch it if the registry cannot serve that shape. 6.0.1 is
# the oldest release whose vendored pkginfo (1.13) parses Metadata-Version 2.4 — the
# format PEP 639 license expressions moved setuptools to — confirmed empirically with
# `twine check` against a wheel built by an unpinned setuptools. twine==5.1.1 vendored
# pkginfo 1.10, which tops out at 2.3 and silently drops Name/Version from a 2.4
# wheel, turning every upload into a false InvalidDistribution failure that has
# nothing to do with the registry under test.
RUN pip install --no-cache-dir build==1.2.2 twine==6.0.1
WORKDIR /work
