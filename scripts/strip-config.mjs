// Post-build guard: удаляет lead-config.php из dist/, если Astro скопировал его из public/.
// Боевой lead-config.php живёт ТОЛЬКО на сервере (Beget). Локальный public/api/lead-config.php —
// dev-конфиг для тестов, он НЕ должен попадать в деплой-архив (иначе на прод уедет dev-URL/секрет).
// Идемпотентно: если файлов нет — молча выходит. Запускается из "npm run build" после astro build.
import { existsSync, rmSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const targets = [
  join(root, "dist", "api", "lead-config.php"),
  join(root, "dist", "lead-config.php"),
];

let removed = 0;
for (const f of targets) {
  if (existsSync(f)) {
    rmSync(f);
    console.log(`[strip-config] удалён из dist: ${f}`);
    removed++;
  }
}
console.log(
  removed
    ? `[strip-config] очищено файлов: ${removed}. dist готов к упаковке.`
    : "[strip-config] lead-config.php в dist не найден — ок, ничего не делаю.",
);
