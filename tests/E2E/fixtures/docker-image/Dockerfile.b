# See Dockerfile.a — the pair shares the `base.bin` layer on purpose.
FROM scratch
COPY base.bin /base.bin
COPY unique-b.txt /unique.txt
