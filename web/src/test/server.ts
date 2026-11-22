import { setupServer } from 'msw/node';

// The one MSW server of the test suite. It starts with no handlers: each test registers the
// endpoints it needs with server.use(...). The setup file resets them after every test, and a
// request that no handler answers fails the test (onUnhandledFrame: 'error', MSW 3's name for
// what MSW 2 called onUnhandledRequest).
export const server = setupServer();
