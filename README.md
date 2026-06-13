# QuesFix 調查資料檢核輔助系統

調查資料回收後的清理輔助系統：依事先撰寫的 Stata 語法檢核條件產生待確認清單，
由檢核人員於網頁介面逐筆確認、修正數值、記錄錯誤類型，並輸出報表與 Stata 修正程式。

## 技術架構

- Laravel 13 + Inertia.js + Vue 3（Breeze 認證骨架）
- MySQL（資料庫 `quesfix`）
- `stata-logic/`：獨立 Composer 套件，Stata 檢核邏輯的 Lexer/Parser/Evaluator/Validator（58 個單元測試）

## 開發環境啟動

```bash
composer install
npm install && npm run build   # 開發時可用 npm run dev
php artisan migrate
php artisan serve              # http://localhost:8000
```

`.env` 已設定本機 MySQL（quesfix/DevQuesFix2026）與 log mailer（驗證信寫入 storage/logs/laravel.log）。

## 已實作功能

- **帳號**：註冊（帳號/姓名/性別/服務單位/Email 確認/密碼強度）、Email 驗證後才可登入、
  第一位註冊者自動為系統管理者、帳號登入、連續 3 次失敗顯示驗證提示、5 次鎖定帳戶、
  忘記密碼（帳號＋Email 雙重比對、連結 10 分鐘）、變更密碼/Email 後強制登出、30 分鐘 session
- **使用者管理**（系統管理者）：列表搜尋、Email 重驗證、專案角色調整/除權、重設密碼、設為管理者、刪除
- **專案管理**：建立/修改/刪除（連帶清除）、成員與角色（至少一位專案管理者、不得自我除權）、
  報表設定（週數變數、訪員代號變數、訪問相關變數）
- **資料格式**：題項/數值標籤 Excel 批次匯入（含查重略過報告）與單筆 CRUD（題目連動更新、孤兒題目清除）
- **檢核條件**：Excel 匯入與 CRUD，匯入時以 stata-logic 驗證語法/變數存在性/型別，
  混用 `&`/`|` 未加括號顯示警告
- **資料匯入**：UTF-8 CSV（表頭含 id、變數存在性檢查、重複 id 略過）
- **檢核執行**：引擎對全樣本評估產生 check_results（重複執行不重複建立）
- **檢核作業**：依樣本/依邏輯雙模式列表（未處理/已處理分群）、檢核介面（關聯題目＋全部題目、
  數值標籤套用、修訂值藍色粗體＋括號原值、暫存修訂紅色粗體）、檢核結果與補問的必填連動、
  樣本鎖定（heartbeat 續鎖＋逾期自動失效＋管理者強制解鎖）、完成檢核交易寫入、
  修正後僅重新確認「與被修正變數相關」的檢核條件（消失/復活/新增/重新確認）
- **匯出**：訪員錯誤報表（xlsx、週數篩選）、檢核結果報表（無誤/需修正/需補問三 sheet）、
  Stata 修正程式 .do（replace＋註解、依修訂時序）、修正後資料 CSV＋讀檔 .do
- **影音**：zip 批次上傳（zip-slip 防護、樣本/題目對應）、檔案存於 webroot 外經權限驗證串流、
  檢核介面彈窗播放截圖＋錄音、連續播放

## 部署前待辦

- [ ] `.env` 改用正式 SMTP（目前 MAIL_MAILER=log）
- [ ] reCAPTCHA：登入頁已留掛載點（連續 3 次失敗），需申請 site key 後接上
- [ ] 大量資料（萬筆以上樣本）時將匯入與檢核執行改為 queue job（服務層已獨立，改掛 job 即可）
- [ ] Nginx + php-fpm 部署設定（上傳大小、逾時）；MariaDB 正式機連線
- [ ] tests/ 內 Breeze 預設認證測試尚未隨客製欄位更新
- [ ] 正式驗收：以 Stata 對同批資料執行全部檢核條件，與引擎結果比對

## stata-logic 引擎

詳見 [stata-logic/README.md](stata-logic/README.md)。語法子集、缺失值語意（正無窮大）、
`anymatch(樣式, 值)` 擴充與匯入驗證規則皆有完整文件與測試。
