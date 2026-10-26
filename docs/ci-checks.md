# Continuous integration checks
The workflow `.github/workflows/asiscontrol-ci.yml` runs on pushes to `main` and pull requests.
It verifies PHP syntax for every backend PHP file, TypeScript type-checking and a clean Vite build with `VITE_API_REQUIRED=true`.

It is a development gate, **not** a substitute for running MySQL migrations on a clean QA database, API authorization integration tests or browser-based end-to-end testing.

Do not deploy while the multiclient implementation status remains "EN DESARROLLO".
