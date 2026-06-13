# quesfix/stata-logic — Stata 檢核邏輯引擎

調查資料檢核輔助系統的核心模組：剖析、驗證並執行以 Stata 語法撰寫的檢核條件。
獨立 Composer 套件，無框架相依，可直接於 Laravel 專案中 `composer require`（path repository）引入。

## 快速開始

```php
use Quesfix\StataLogic\{LogicEngine, VariableCatalog, ArrayResolver};

// 1. 變數目錄（對應 ques_vars：名稱 → 型別）
$catalog = new VariableCatalog([
    'a01'   => VariableCatalog::TYPE_NUMERIC,
    'Marry' => VariableCatalog::TYPE_STRING,
]);

// 2. 匯入時驗證
$result = LogicEngine::validate('(a01 == 1 & Marry != "已婚")', $catalog);
if (! $result->ok()) {
    // 顯示 $result->errors，拒絕匯入
}
foreach ($result->warnings as $warning) {
    // 顯示警告（如混用 &/| 未加括號），請使用者確認後仍可匯入
}

// 3. 檢核求值（每個樣本一列；值可直接用 DB 取出的字串）
$ast = LogicEngine::parse($logic);             // AST 可重複使用
$row = ArrayResolver::fromStrings(
    ['a01' => '1', 'Marry' => '從未結婚'],     // origin_data + fix_data 合併後的最新值
    $catalog,
);
$hit = LogicEngine::evaluate($ast, $row);      // true = 觸發檢核條件
```

## 支援的語法子集

依 387 條實際檢核條件歸納（tests/fixtures/real_rules.json）：

| 類別 | 語法 |
|---|---|
| 比較 | `==` `!=`（或 `~=`）`>` `<` `>=` `<=` |
| 邏輯 | `&` `\|` `!`（或 `~`） |
| 算術 | `+` `-` `*` `/` `^`（次方） |
| 常數 | 數值（`991`、`0.25`）、字串（`"從未結婚"`，UTF-8）、缺失值（`.`、`.a`～`.z`） |
| 函數 | `inlist(x, v1, …)`、`inrange(x, lo, hi)`、`abs(x)`、`anymatch(樣式, 值)` |
| 註解 | `//` 至行尾 |

`anymatch(樣式, 值)` 為本系統的擴充函數（非 Stata 原生）：樣式中 `?` 匹配單一字元、
`*` 匹配任意長度，凡匹配到的任一變數值等於「值」即成立。
舊記法 `anymatch(p), value(v)` 不再支援，匯入前請改寫為 `anymatch(p, v)`。

## Stata 語意（刻意遵循）

- **缺失值為正無窮大**：`x > 65` 在 x 缺失時為「真」；慣用防呆 `x < .` 表示「x 非缺失」。
- **算術遇缺失值即缺失**：`. + 1 = .`；**除以零結果為缺失值**（不會丟例外）。
- **比較與邏輯運算永遠回傳 1/0**，不產生缺失值。
- **邏輯運算中缺失值視為真**（非零即真）：`. & 1 = 1`、`!. = 0`。
- **`&` 優先於 `|`**：`A | B & C` ＝ `A | (B & C)`。Validator 對此模式發出警告。
- **變數名稱區分大小寫**。
- **比較串接由左至右**：`3 > 2 > 1` ＝ `(3 > 2) > 1` ＝ 假。
- **單元負號優先序低於 `^`**：`-2^2 = -4`。
- 字串僅能與字串比較；字串不能參與算術或直接作為邏輯條件（驗證期即報錯）。

## 驗證規則（Validator）

| 級別 | 條件 |
|---|---|
| 錯誤（拒絕匯入） | 語法錯誤、變數不存在於資料格式、字串/數值型別混用、anymatch 樣式無匹配變數 |
| 警告（須確認） | 同層混用 `&` 與 `|` 且未以括號分組 |

## 架構

```
Lexer → Token[] → Parser → AST（Ast/*） → Evaluator（求值）
                                        → Validator（匯入驗證，需 VariableCatalog）
LogicEngine：靜態 facade
VariableResolverInterface / ArrayResolver：求值時的變數來源
```

與資料庫的對應：`VariableCatalog` 由 `ques_vars` 建構（`var_type`：1=選項、2=數值 → numeric；3=文字 → string）；
求值用的列資料 = `origin_data` 套上 `fix_data` 最新修訂後，以 `ArrayResolver::fromStrings()` 轉換
（空字串與 `.` 視為數值缺失）。建議將 `parse()` 產生的 AST 於 `check_items` 匯入時快取重用。

## 測試

```bash
composer install
./vendor/bin/phpunit
```

`tests/RealRulesTest.php` 會將 387 條實際檢核條件整批剖析。其中 3 條
（`same_lg_312`、`c_lg_57`、`c_lg_58`）在來源 Excel 中括號不平衡（多一個右括號），
屬來源資料錯誤，引擎正確拒絕；修正 Excel 後請自 `KNOWN_INVALID` 清單移除。

## 已知未支援（如有需要再擴充）

- 字串函數（`regexm`、`substr`、`strpos` 等）
- `cond()`、`min()`、`max()` 等其他 Stata 函數
- 跨樣本運算（目前一次求值一個樣本）
