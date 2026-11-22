# Smoke Detector: web client

React 18 + Vite + TypeScript (see `docs/HLD.md` §6). Needs Node 26 and npm.

- Install: `npm ci` (or `make web-setup` from the repository root). Install scripts are off (`.npmrc`).
- Run: `npm run dev` serves http://localhost:5173 and proxies `/api` to the backend (`BACKEND_DEV_PORT` in `vite.config.ts`).
- Test: `npm test` (Vitest, jsdom, MSW: a request no test mocked fails). Everything CI runs: `npm run check` (`make web-check`).
- Build: `npm run build` writes `dist/`.
- API types: after changing `../api/openapi.yaml`, run `npm run api:types` and commit `src/api/schema.d.ts`; `npm run api:check` fails while they differ.
