import { spawnSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
// A dedicated SQLite file. Never migrate/reset the operator's database.
mkdirSync('tmp', { recursive: true });
const database = resolve('tmp/browser.sqlite');
writeFileSync(database, '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false',
  APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
  SESSION_DRIVER: 'database', CACHE_STORE: 'array', QUEUE_CONNECTION: 'database',
  APP_URL: 'http://127.0.0.1:8091', LOG_CHANNEL: 'stderr' };
const setup = spawnSync('php', ['artisan', 'migrate', '--seed', '--force'], { env, stdio: 'inherit' });
if (setup.status !== 0) process.exit(setup.status ?? 1);
const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8091'], { env, stdio: 'inherit' });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(); });
server.on('exit', code => process.exit(code ?? 0));
