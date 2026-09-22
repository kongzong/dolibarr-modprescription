# modPrescription — Dolibarr 处方与处方笺模块

Dolibarr 22.0.x 外部模块：面向中医馆/中西医结合诊所的处方。零 core 修改。
医疗模块群一期第三个模块（MVP 最后一块），依赖 [modPatient](https://github.com/kongzong/dolibarr-modpatient) ≥ 0.1.3
与 [modMedRecord](https://github.com/kongzong/dolibarr-modmedrecord) ≥ 0.1.3。

当前版本：**0.1.1（2026-09-22，modPharmacy 前置改动）**：业务类新增 `markDispensed()` / `markDispenseUndone()`（已签发 ⇄ 已发药的状态桥，由 modPharmacy 驱动；乐观锁 + PRESCRIPTION_DISPENSE[_UNDONE] 审计），`llx_prescription` 加 `date_dispensed` / `fk_user_dispensed`（升级脚本 `sql/upgrade/upgrade_0.1.0_to_0.1.1.sql`，幂等，已有行保持 NULL，不需重启用）。0.1.0 见下方规格。规格见 [docs/spec-prescription-v0.1.md](docs/spec-prescription-v0.1.md)。
2026-09-21：业务类改名 `PrescriptionSheet`（原 `Prescription`）——REST API 类名被 core 钉死为模块目录名，业务类让位；依赖同步为 modMedRecord ≥ 0.1.3（其业务类同期改名 `MedicalRecord`）。

## 设计要点

- 两种处方类型：**中药饮片方（TCM）**（药名/克数/煎法 + 剂数 + 煎服法）与**西药/中成药方（WM）**（单次剂量/途径/频次/天数/总量）
- 状态机 **草稿 → 已签发 → 已作废**，`已发药` 值预留给 modPharmacy；签发即锁无宽限，改方 = 作废 + 复制；永不物理删除
- **过敏阻断**：保存与签发时逐行对照患者过敏史（`PatientAllergy::findConflicts`）；命中即阻断；
  轻/中度可由持 `override` 权限者填原因放行并审计，**重度不可放行**
- 编号 `CF-YYYYMMDD-NNN` 按日流水，取号与保存同事务
- 从病历页一键开方（hook `medrecordcard`），处方回链病历；患者卡片"处方"Tab
- 处方笺 PDF（A4，常量可切 A5）按《处方管理办法》前记/正文/后记结构，两版式；草稿/作废水印
- 处方不算金额（modClinicPay 按产品价计费）；药品行可关联产品或自由文本（常量开关）
- 权限一级形式 `read / write / issue / void / override / admin`；审计复用 `llx_patient_audit`，动作 `PRESCRIPTION_*`
- 模块 ID `501620`；左菜单挂 modPatient 的"诊所"顶级菜单

## 阶段状态

| 阶段 | 内容 | 状态 |
|---|---|---|
| 0 modMedRecord 0.1.1 | 病历页 hook 占位提示修正 | 已发布 `v0.1.1` |
| 1 骨架 | descriptor、3 张表 + 4 字典 + 种子、常量、权限、菜单、hook 类（病历页按钮 + 列表）、患者 Tab（时间线）、设置页、测试 | 已完成（2026-09-20 UI 验收通过） |
| 2 处方与编号 | `PrescriptionSheet` 类（过敏分级阻断、状态机、字段级审计 diff）、`PrescriptionNumbering` + 6 单元 + 6 并发集成测试、TCM/WM 两种明细编辑器、药品 AJAX 选择器（按分类过滤）、列表 | 已完成（2026-09-21 UI 验收通过；随后接入 patient 0.1.3 上下文条与面包屑） |
| 3 处方笺 PDF | `ModelePDFPrescription` 抽象基类（前记/后记/页码/水印/分页共用）+ `pdf_cf_tcm`（饮片三列排布 + 剂数煎服法）+ `pdf_cf_wm`（五列表格）；签发自动生成；`pdf.php` 下载写审计；`tests/integration/pdf.php` 真实 TCPDF 渲染 4 样例 × A4/A5 | 代码完成，CLI 验证通过（14 单元 + 8 渲染），人工看图待做 |
| 4 集成面 | REST 6 端点、字典 CSV 导入、停用→启用全流程、`v0.1.0` | 已完成（2026-09-21 admin token REST 实测 + 字典导入/停用→启用/权限矩阵/PDF 人工验收通过） |

## REST API

所有端点需 `DOLAPIKEY`。URL 前缀 = 模块目录名（core 约束）。业务类为 `PrescriptionSheet`
（REST 注册类名被 core 钉死为 `ucwords('prescription')` = `Prescription`，业务类让位命名，机制见 spec §3.7）。
状态机、过敏阻断、权限规则与页面完全一致；不返回患者证件号。签发后自动生成处方笺 PDF。

```
GET  /api/index.php/prescription/prescriptions?q=&patient=&medrecord=&doctor=&type=&status=&from=&to=&limit=&page=   # read；status 缺省为非作废
GET  /api/index.php/prescription/prescriptions/{id}      # read；写 PRESCRIPTION_READ 审计；含明细
POST /api/index.php/prescription/prescriptions           # write；建草稿；过敏冲突 409（附 allergy_hits / override_possible / override_required_reason），带 allergy_override_reason 可放行
PUT  /api/index.php/prescription/prescriptions/{id}      # write；改草稿（患者/类型/编号不可变）；不可编辑 403
POST /api/index.php/prescription/prescriptions/{id}/issue    # issue；签发即锁并自动生成 PDF；缺行/用法不完整 400；过敏冲突 409
POST /api/index.php/prescription/prescriptions/{id}/void     # void；body {reason}，缺原因 400
POST /api/index.php/prescription/prescriptions/{id}/copy     # write；返回草稿形态 + copy:true（不落库），写 PRESCRIPTION_COPY 审计
```

PDF 下载走核心 `documents` API（`modulepart=prescription`）。

## 处方笺 PDF

- 走核心 `commonGenerateDocument()`：descriptor `models=1`，模板在 `core/modules/prescription/doc/pdf_cf_tcm.modules.php` / `pdf_cf_wm.modules.php`，
  `Prescription::generateDocument()` 按类型选模板；文件存 `DOL_DATA_ROOT/prescription/<编号>/<编号>.pdf`，并按核心机制索引到 ECM
- 版式按《处方管理办法》前记 / 正文 / 后记：机构名、"处方笺"、编号 / 费别自费 / 日期、患者姓名性别年龄卡号、科别、临床诊断；
  `Rp.` 正文；医师签名、药品金额（留空）、审核 / 调配 / 核对 / 发药四个签名位、放行说明、保存年限提示
- 饮片方：每行 3 味（A5 为 2 味）"药名 12g（后下）"，末尾"共 N 剂 · 自煎/代煎/颗粒"与煎服法；西药方：序号 / 药品名称（规格 + 编码）/ 数量 / 用法用量 / 备注，命中放行行标 ※
- 分页：正文超页自动换页并重复前记（标题带"（续）"），西药表头随页重复；页脚页码"第 x / y 页"
- 水印：草稿灰色"草稿"斜向，作废红色"作废"；已签发无水印
- 中文字体 TCPDF 内置 `stsongstdlight`（chinadoc 已验证）；纸张 `PRESCRIPTION_PDF_FORMAT` A4 默认，A5 可切
- 处方页"处方笺 PDF"按钮 → `pdf.php?id=`：草稿每次重生成，已签发/作废缺文件时重生成，每次下载写 `PRESCRIPTION_PRINT`；签发时自动生成一次

## 过敏阻断规则（在类内强制，页面与 REST 同样受控）

| 命中最高严重度 | 结果 |
|---|---|
| 无 | 正常保存 |
| 轻度 1 / 中度 2 | 阻断；持 `override` 权限者勾选"确认放行"并填原因后可保存，写 `PRESCRIPTION_ALLERGY_OVERRIDE` 审计，命中行标 ※ |
| 重度 3 | 阻断，任何人不可放行 |

校验点：保存草稿（create/update）与签发。匹配规则来自 modPatient 的 `PatientAllergy::findConflicts`：
关联产品精确命中，或药名与过敏名互相包含。

## 状态机与权限

| 动作 | 条件 |
|---|---|
| 新建/编辑草稿 | `write`；非 admin 只能改开方医生为自己或自己创建的草稿；医生须已登记 |
| 签发 | `issue` 且为开方医生或 admin；至少一行；TCM 需每味克数 + 剂数；WM 每行需 剂量+频次+天数 或用法补充；签发即锁，无宽限 |
| 作废 | `void`；原因必填；已发药（药房写入的状态 2）不可在此作废 |
| 复制为新处方 | `write`；复制类型/明细/剂数/用法为新草稿，写 `PRESCRIPTION_COPY` |
| 阅读 | `read`；打开处方页写 `PRESCRIPTION_READ` |

## 安装

```
git clone https://github.com/kongzong/dolibarr-modprescription htdocs/custom/prescription
```
先启用 modPatient 与 modMedRecord，再启用 Prescription。停用只移除常量/权限/菜单/Tab/hook，不删任何表或 PDF。

## 测试

```
php tests/run_all.php                 # 结构 + 编号行为测试，无需数据库
php tests/integration/numbering.php   # 真实 MariaDB：20 并发取号、锁交接、回滚释放、缺表失败（隔离临时表）
php tests/integration/pdf.php         # 真实 TCPDF：两版式 × 签发/草稿/放行/作废 4 样例，页数与文本断言；PRESCRIPTION_PDF_TEST_FORMAT=A5 切纸张
```

## 开发约定

遵循 [custom/DOLIBARR-MODULE-DEVELOPMENT.md](../DOLIBARR-MODULE-DEVELOPMENT.md)；环境与模块状态见 [custom/AGENTS.md](../AGENTS.md)。
