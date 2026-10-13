## System requirements

- **Docker** — Docker Desktop (Windows 10/11 with WSL 2, or macOS 12+) or Docker Engine 20.10+ (Linux), on a 64-bit x86 host
- **CPU** — 1 core minimum, 2 cores recommended
- **RAM** — 4 GB of system memory; the analysis itself is capped at 2 GB inside the container
- **Disk** — ~1 GB for the image, plus ~0.5 GB of results per Mbp of sequence analysed
- **Browser** — any current 64-bit browser, for the local interface at `http://localhost:8080`

Scanning takes about one minute per Mbp of input on a single core. A submission
of up to roughly 1 Mbp of total sequence runs within the default settings; for
larger batches, start the container with a higher ceiling
(`docker run -e PP_MEMORY_LIMIT=8G ...`) or split the input into several runs.
