# modPrescription V0.1 规格 — 处方与处方笺

> 状态：**评审稿**（2026-09-20）。医疗模块群一期第三个模块，一期 MVP 的最后一块。
> 依赖 modPatient ≥ 0.1.3（患者上下文条）、modMedRecord ≥ 0.1.3（0.1.3 起业务类为 `MedicalRecord`）。
> 上位规划：`custom/HEALTHCARE-MODULES-PLAN.md` §3.3；技术约定：`custom/DOLIBARR-MODULE-DEVELOPMENT.md`。
> 立项答案：中医馆/中西医结合（饮片处方进 V0.1）、单机构、药品目录未定、非医保纯自费。

## 0. 相对规划稿的调整

| 项 | 规划稿 | 本规格 | 理由 |
|---|---|---|---|
| 过敏校验 | "命中即阻断 + 提示" | 命中即阻断；轻/中度可由持 `override` 权限者填原因放行并审计；**重度不可放行** | 临床上有脱敏后可用的情况，但重度过敏必须硬阻断 |
| 处方保存年限 | ≥1 年 | 常量默认 1 年，只展示不删除 | 《处方管理办法》第 50 条：普通处方 1 年，精二/毒性 2 年，麻醉/精一 3 年。本模块不做管制药品分级，年限只作提示 |
| 编号并发 | "复用 chinadoc 编号扩展方式" | 复制 patient/medrecord 已验证的行锁方案（`CF-YYYYMMDD-NNN`） | 处方是本模块自有对象，不挂核心 `getNextNumRef`，无需 chinadoc 的模块注入 |
| 药品金额 | 走原生 price 函数 | V0.1 处方**不计算金额**，处方笺"金额"栏留空；由 modClinicPay 按产品价计费 | 避免处方与收费两处金额来源 |
| 药品目录 | — | 明细可关联产品，也允许**自由文本行**（常量开关，默认开） | 药品目录未定，先保证能开方；目录建好后关闭自由行 |

## 1. 定位与已核实地基

一张处方 = 一名医生为一名患者在一次就诊中开具的用药指令，分**中药饮片方（TCM）**与**西药/中成药方（WM）**两种类型，各自一套明细字段与处方笺版式。
状态机：**草稿 → 已签发 → 已作废**；`已发药` 状态值预留给 modPharmacy，本模块不写入。签发后锁定，改方只能作废后"复制为新处方"。

本机 22.0.4 源码已核实（2026-09-20）：

| 依赖 | 出处 | 结论 |
|---|---|---|
| 自定义对象生成 PDF | `commonobject.class.php:5883` `commonGenerateDocument($modelspath, $modele, ...)` 在 `/` 与 `modules_parts['models']` 目录下找 `<modelspath>pdf_<modele>.modules.php` | descriptor `models => 1`，模板放 `prescription/core/modules/prescription/doc/pdf_cf_tcm.modules.php` / `pdf_cf_wm.modules.php`，`Prescription::generateDocument()` 按类型选模板；沿用 chinadoc 的 `models` 必须标量 1 结论 |
| PDF 输出目录 | `conf.class.php:754` 对每个启用模块设 `$conf-><module>->dir_output = DOL_DATA_ROOT/<module>` | 文件存 `dolibarr_documents/prescription/<ref>/<ref>.pdf` |
| 中文 PDF | `chinadoc/.../pdf_chinadoc.modules.php:43,103,113` `pdf_getInstance()` + `stsongstdlight` | 直接复用字体与 TCPDF 初始化写法 |
| 病历页注入 | `hookmanager.class.php:196` output 类 hook 通过 `->resprints` 回传；medrecord `card.php` 已调 `executeHooks('printMedRecordCard')` 并打印 `resPrint`；hook 类命名 `class/actions_prescription.class.php` → `ActionsPrescription`（DEV.md §二.11） | 本模块 `module_parts['hooks'] = array('medrecordcard')`，方法 `printMedRecordCard()` 输出"开中药方 / 开西药方"按钮与该病历的处方列表 |
| 患者卡片 Tab | modPatient 0.1.1 `complete_head_from_modules('patient')` | descriptor 声明 `patient:+prescription:...` |
| 过敏拦截 | `PatientAllergy::findConflicts($fkPatient, $fkProduct, $label)`：产品精确命中 + 名称包含匹配，返回命中的活动过敏（含 severity） | 保存与签发时逐行调用 |
| 医生 / 摘要 / 审计 | `patient_doctor_options()`、`patient_get_summary()`、`patient_audit()` | 同 medrecord 用法；审计动作前缀 `PRESCRIPTION_` |
| 产品 | `product.class.php` `Product`：`ref, label, fk_unit, type, status`；`Form::select_produits()` AJAX 选择器 | 明细关联 `fk_product`，快照 `product_ref` / `label` |

## 2. 前置改动（独立提交）

- **modMedRecord 0.1.1**：`card.php` 中 hook 输出后，"处方模块未安装"提示只在 `resPrint` 为空时显示（当前逻辑在 `reshook==0` 时总显示）。无其他改动。

## 3. 功能范围（做）

### 3.1 数据

- `llx_prescription`：`rowid, entity, ref(唯一 CF-YYYYMMDD-NNN), presc_type(TCM/WM), fk_patient, fk_medrecord(可空), fk_doctor, fk_department, date_presc(datetime), diagnosis_text(快照：主诊断 + 中医病名 + 证型), doses(剂数, TCM), usage_note(煎服法/总体用法 text), decoct_mode(SELF 自煎/CLINIC 代煎/GRANULE 颗粒, TCM), note(备注), allergy_override_reason(可空), status(0草稿/1已签发/2已发药[预留]/9已作废), date_issued, fk_user_issue, void_reason, date_void, fk_user_void, model_pdf, fk_user_creat, fk_user_modif, date_creation, tms`
- `llx_prescription_line`：`rowid, fk_prescription, position, fk_product(可空), product_ref(快照), label(必填快照), qty(decimal 10,3), qty_unit(varchar 16：TCM 默认 g；WM 为总量单位), decoct_code(TCM 煎法字典 可空), dose(WM 单次剂量 decimal), dose_unit(字典 code), route_code(字典), freq_code(字典), days(WM 天数), sig_note(用法补充/自由文本), allergy_hit(0/1 保存时是否命中过敏, 供审计与打印标注)`
- `llx_prescription_sequence`：`ref_prefix PK, last_value`
- 字典（`rowid` 无自增）：`llx_c_prescription_decoct`（先煎/后下/包煎/另煎/烊化/冲服/打碎/先煎去沫…）、`llx_c_prescription_route`（口服/外用/舌下/吸入/滴眼/滴鼻/直肠/皮下/肌注/静滴）、`llx_c_prescription_freq`（qd/bid/tid/qid/qn/q8h/q12h/qod/prn/st…，label 每日一次 等）、`llx_c_prescription_dose_unit`（mg/g/ml/片/粒/袋/丸/支/滴/喷/贴）
- 常量：`PRESCRIPTION_RETENTION_YEARS`（默认 1，仅展示）、`PRESCRIPTION_ALLOW_FREE_LINES`（默认 1）、`PRESCRIPTION_PDF_FORMAT`（默认 A4）、
  `PRESCRIPTION_TCM_CATEGORY` / `PRESCRIPTION_WM_CATEGORY`（产品分类 id，非空时明细选择器只列该分类；空则全部产品）、
  `PRESCRIPTION_TCM_DEFAULT_USAGE`（默认煎服法文案："水煎服，每日一剂，分早晚两次温服"）

### 3.2 编号

`CF-YYYYMMDD-NNN` 按日流水，取号在 create 事务内；实现复制 `MedRecordNumbering`，历史号从 `llx_prescription.ref` 回填。

### 3.3 过敏校验（红线）

- 触发点：草稿保存（create/update）与签发。逐行调用 `findConflicts(fk_patient, fk_product, label)`。
- 命中规则：任一行命中 → 阻断保存，页面列出命中行与对应过敏（名称、严重度）。
- 放行：命中的最高严重度 ≤ 2 且用户持 `override` 权限，可勾选"确认放行"并**必填原因**，原因存 `allergy_override_reason`，写审计 `PRESCRIPTION_ALLERGY_OVERRIDE`（含命中明细）。**严重度 3 无法放行**。
- 命中行 `allergy_hit=1`，处方笺上该行标 `※` 并在后记注明"已由医师确认放行"。
- 无 `patient profile` 权限的用户看不到过敏详情，但阻断仍生效（提示"存在过敏冲突，请联系医生"）。

### 3.4 状态机与权限

| 动作 | 从 → 到 | 权限 | 条件 |
|---|---|---|---|
| 新建/编辑草稿 | — → 0 / 0 → 0 | `write` | 非 admin 只能改 `fk_doctor` 为自己或自己创建的草稿；医生须已登记；过敏校验通过 |
| 签发 | 0 → 1 | `issue` 且为接诊医生或 admin | 至少一行明细；TCM 需 `doses ≥ 1`；WM 每行需 dose/freq/days 或 sig_note 之一；过敏校验通过；签发时生成 PDF |
| 作废 | 0/1 → 9 | `void` | 必填原因；已发药（2）不可作废（留给 pharmacy 处理退药） |
| 复制为新处方 | 任意 → 新草稿 | `write` | 复制类型、明细、剂数、用法，`fk_medrecord` 可改指向新病历 |
| 打印/下载 PDF | 任意 | `read` | 草稿 PDF 带"草稿"水印；写 `PRESCRIPTION_PRINT` 审计 |
| 阅读 | — | `read` | 打开处方页写 `PRESCRIPTION_READ` |

权限一级形式：`read / write / issue / void / override / admin`。签发后**不设宽限窗口**（处方比病历更严格）。

### 3.5 页面

- 左菜单（`fk_mainmenu=clinic`）：处方列表、新建处方
- **处方页** `prescription/card.php`：
  - 头部：患者摘要横幅（含过敏红色警示）、关联病历链接、编号、状态、类型、医生、日期、诊断快照
  - **TCM 明细编辑器**：网格式，每行 药名（产品自动补全或自由文本）/ 克数 / 煎法；末尾 剂数、煎法模式、煎服法文案（默认常量）
  - **WM 明细编辑器**：每行 药名 / 单次剂量 + 单位 / 途径 / 频次 / 天数 / 总量 + 单位 / 备注；总量可由 剂量×频次次数×天数 前端预估，医生可改
  - 过敏冲突面板：阻断时列出；持 `override` 者见"确认放行 + 原因"
  - 动作：保存草稿 / 签发 / 作废 / 复制为新处方 / 下载 PDF
- **列表** `prescription/list.php`：编号/卡号/姓名、类型、医生、状态、日期区间；默认隐藏作废
- **患者卡片 Tab "处方"**：该患者处方时间线 + 新建（预填患者）
- **病历页注入**（hook）：按钮"开中药方"/"开西药方"（预填患者、病历、医生、科室、诊断快照）+ 该病历下处方列表（编号/类型/状态/PDF）
- **设置页**：常量、四字典入口、字典 CSV 导入（复用 medrecord 的导入类思路，`编码,名称[,排序]`）

### 3.6 处方笺 PDF（两版式，A4 纵向默认，常量可切 A5）

按《处方管理办法》第 6 条的前记/正文/后记结构：

- **前记**：机构名称（`$mysoc->name`）、"处方笺"标题、处方编号、费别"自费"、患者姓名/性别/年龄、就诊卡号、科别、临床诊断、开具日期
- **正文** `Rp.`：
  - WM：表格 序号 / 药品名称（规格） / 数量 / 用法用量（单次剂量+途径+频次+天数）/ 备注；命中放行行标 ※
  - TCM：药名与克数按每行 3–4 味排布（`药名 12g`），煎法以小字附于药名后（`（后下）`）；表格下方 `共 N 剂` + 煎法模式 + 煎服法
- **后记**：医师签名（打印签发医生姓名 + 手签线）、审核/调配/核对/发药 四个药师签名位、药品金额（留空）、"※ 过敏冲突已由医师确认放行" 说明行（仅当存在）
- 草稿状态 PDF 斜向灰色"草稿"水印；已作废 PDF 红色"作废"水印 + 原因
- 中文字体 `stsongstdlight`；单页放不下时换页并重复前记与"（续）"

### 3.7 集成点与 REST

- hook 类 `class/actions_prescription.class.php`（`ActionsPrescription::printMedRecordCard`）
- `lib/prescription.lib.php`：`prescription_status_label/badge`、`prescription_list_by_medrecord($db, $fkMedrecord)`、`prescription_list_by_patient(...)`、字典读取
- REST（类 `Prescription`；勘误 2026-09-21：业务类因 REST 命名约束改名 `PrescriptionSheet`，API 类占回 `Prescription`。机制见下"REST 命名约束"）：
  - `GET prescription/prescriptions?q=&patient=&medrecord=&doctor=&type=&status=&from=&to=`（read）
  - `GET prescription/prescriptions/{id}`（read，写 PRESCRIPTION_READ；含明细）
  - `POST prescription/prescriptions`（write；过敏冲突返回 409 并附命中明细；带 `allergy_override_reason` 且有权限可放行）
  - `PUT prescription/prescriptions/{id}`（write，草稿）
  - `POST prescription/prescriptions/{id}/issue`（issue，签发后自动生成处方笺 PDF）
  - `POST prescription/prescriptions/{id}/void`（void，`{reason}`）
  - `POST prescription/prescriptions/{id}/copy`（write；返回草稿形态 + `copy:true`，调用方再用 POST 创建）
  - PDF 下载走核心 `documents` API（`modulepart=prescription`），本模块不另开端点

- REST 命名约束（2026-09-21 实测结论，影响所有自定义模块）：
  - `htdocs/api/index.php` 的 URL 分支把注册类名强制为 `ucwords(<URL 段>)`，且 URL 段 = 模块目录名（core 硬编码，`getModuleDirForApiClass` 无自定义模块复数映射）
  - 因此 API 类名必须等于 `ucwords(模块目录名)`：本模块 URL `/prescription/...` → API 类 `Prescription`，业务类让位为 `PrescriptionSheet`
  - PHP 类名大小写不敏感：业务类与 API 类不能只差大小写（modMedRecord 的 `MedRecord`/`Medrecord` 曾因此 fatal）
  - 正确 URL 族为 `/<模块目录>/<@url 路径>`：`/prescription/prescriptions`、`/medrecord/records`、`/patient/patients`（不带模块目录的 `/patients` 等单段形式走不到自定义模块）
  - PDF 下载走核心 `documents` API（`modulepart=prescription`），本模块不另开端点

## 4. 明确不做（V0.1）

- 药品金额与收费（modClinicPay）；发药、库存扣减、`已发药` 状态写入（modPharmacy）
- 合理用药审方（相互作用、剂量上限、儿童剂量换算）
- 管制药品分级（麻醉/精神/毒性）、专用处方笺颜色、处方权分级
- 电子签名证书、电子处方外流
- 协定方/经验方模板库（V0.2 候选）
- 颗粒剂按"袋"的换算（V0.1 颗粒方仍按克记，`decoct_mode=GRANULE` 仅标注）
- PostgreSQL

## 5. 红线

1. 处方**不可物理删除**：主表与明细无 DELETE 路径（草稿明细随保存整体替换，签发后不可编辑）；作废保留全部字段；`remove()` 不删表
2. 过敏校验在类内强制执行（不依赖页面），REST 同样受控；重度不可放行；放行必留原因与审计
3. 签发后锁定，无宽限；改方 = 作废 + 复制
4. 读写全部审计到 `llx_patient_audit`：`PRESCRIPTION_CREATE/MODIFY/ISSUE/VOID/READ/PRINT/ALLERGY_OVERRIDE/COPY`
5. 处方页、PDF、REST 永不输出患者证件号
6. 医生字段只能取已登记医生；签发人必须是接诊医生或 admin
7. 零 core 修改；API 先 grep；`models` 标量 1；字典 `rowid` 无自增、`tabhelp` 非空
8. 编号取号与保存同事务，失败整体回滚

## 6. 阶段划分

| 阶段 | 交付 | 人工验证 |
|---|---|---|
| 0 modMedRecord 0.1.1 | §2 一处改动 + 版本 + 标签 | 病历页无处方模块时提示不重复出现 |
| 1 骨架 | descriptor（ID 501620、models=1、hooks、tabs、dictionaries）、3 张表 + 4 字典 + 种子、常量、权限、菜单、语言、hook 类壳、测试运行器 | UI 启用；字典页 4 张表；"诊所"左菜单；患者卡片 Tab 壳；病历页出现"开方"按钮区 |
| 2 处方与编号 | `Prescription`/`PrescriptionLine` 类（create 取号同事务 / update / issue / void / copy / 过敏校验）、`PrescriptionNumbering` + 单元与并发集成测试、处方页两种明细编辑器、列表、患者 Tab、病历 hook 列表 | 从病历页开中药方与西药方各一张；命中过敏被阻断、override 放行留痕；签发锁定；并发 20 不重号 |
| 3 处方笺 PDF | `modules_prescription.php` 抽象类、`pdf_cf_tcm` / `pdf_cf_wm` 两模板、签发自动生成、草稿/作废水印、下载写审计、chinadoc 式隔离 PDF 结构测试 | 两版式 PDF 中文正确、前记后记齐全、超 20 味饮片分页、※ 标注与放行说明 |
| 4 集成面 | REST 6 端点、设置页字典导入、停用→启用全流程、`v0.1.0` | REST 权限矩阵（409 过敏、403 签发）；停用→启用无损 |

## 7. 验收标准

- A. 干净库 UI 启用 → 开方 → 签发 → 停用 → 启用，处方、明细、编号、PDF 文件无损
- B. 并发 20 建方编号唯一连续；作废不释放已签发编号
- C. 过敏：轻度命中阻断 → override 放行留痕并 PDF 标 ※；重度命中任何人不可放行；REST 返回 409
- D. 状态机：草稿可改；签发后所有写入口拒绝（无宽限）；作废必填原因；`已发药` 值本模块不可写
- E. 权限：无 `read` 用户患者卡片无 Tab、病历页无开方按钮、REST 403；非接诊医生 `issue` 被拒
- F. PDF：TCM 与 WM 各一份通过人工核对（前记 8 项、正文、后记签名位）；草稿水印；作废水印
- G. 病历联动：从病历页开方后，病历页列出该处方；处方页能跳回病历
- H. `tests/run_all.php` 全过；README/descriptor/API 版本一致；AGENTS.md 更新

## 8. 开放项（2026-09-20 已定）

1. **处方笺纸张 A4 纵向**（用户定），常量 `PRESCRIPTION_PDF_FORMAT` 默认 `A4`，可切 `A5`
2. **自由文本药品行默认允许**（`PRESCRIPTION_ALLOW_FREE_LINES=1`）；药品目录建好后由管理员关闭，关闭后未关联产品的行在保存时被拒
3. **饮片默认煎服法**："水煎服，每日一剂，分早晚两次温服"，常量可改
4. **产品分类不自动创建**：两个分类常量默认空（选择器列全部产品）；设置页提供下拉从现有产品分类中选择

## 9. 模块标识

- 目录 `htdocs/custom/prescription/`，类 `modPrescription`，常量 `MAIN_MODULE_PRESCRIPTION`
- 模块 ID **501620**；权限 ID `50162011/21/31/41/51/61`（read/write/issue/void/override/admin）
- `depends = array('modPatient', 'modMedRecord')`；语言 `langs/zh_CN/prescription.lang`、`langs/en_US/prescription.lang`
- 仓库 `github.com/kongzong/dolibarr-modprescription`（推送前由维护者创建）
