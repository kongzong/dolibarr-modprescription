-- modPrescription seed dictionaries. Idempotent on re-enable (fixed rowid +
-- unique code, run_sql accepts DB_ERROR_RECORD_ALREADY_EXISTS).

INSERT INTO llx_c_prescription_decoct (rowid, pos, code, label, active) VALUES
(1,  10,  'XIANJIAN',  '先煎', 1),
(2,  20,  'HOUXIA',    '后下', 1),
(3,  30,  'BAOJIAN',   '包煎', 1),
(4,  40,  'LINGJIAN',  '另煎', 1),
(5,  50,  'YANGHUA',   '烊化', 1),
(6,  60,  'CHONGFU',   '冲服', 1),
(7,  70,  'DASUI',     '打碎', 1),
(8,  80,  'JIUJIAN',   '久煎', 1),
(9,  90,  'PAOFU',     '泡服', 1),
(10, 100, 'JIANTANG',  '煎汤代水', 1);

INSERT INTO llx_c_prescription_route (rowid, pos, code, label, active) VALUES
(1,  10,  'PO',   '口服', 1),
(2,  20,  'EXT',  '外用', 1),
(3,  30,  'SL',   '舌下含服', 1),
(4,  40,  'INH',  '吸入', 1),
(5,  50,  'OPH',  '滴眼', 1),
(6,  60,  'NAS',  '滴鼻', 1),
(7,  70,  'PR',   '直肠给药', 1),
(8,  80,  'SC',   '皮下注射', 1),
(9,  90,  'IM',   '肌肉注射', 1),
(10, 100, 'IV',   '静脉滴注', 1),
(11, 110, 'TOP',  '局部涂敷', 1),
(12, 120, 'ACU',  '穴位贴敷', 1);

INSERT INTO llx_c_prescription_freq (rowid, pos, code, label, active) VALUES
(1,  10,  'QD',   '每日一次', 1),
(2,  20,  'BID',  '每日两次', 1),
(3,  30,  'TID',  '每日三次', 1),
(4,  40,  'QID',  '每日四次', 1),
(5,  50,  'QN',   '每晚一次', 1),
(6,  60,  'QM',   '每晨一次', 1),
(7,  70,  'Q8H',  '每8小时一次', 1),
(8,  80,  'Q12H', '每12小时一次', 1),
(9,  90,  'QOD',  '隔日一次', 1),
(10, 100, 'QW',   '每周一次', 1),
(11, 110, 'PRN',  '必要时', 1),
(12, 120, 'ST',   '立即一次', 1),
(13, 130, 'AC',   '饭前', 1),
(14, 140, 'PC',   '饭后', 1);

INSERT INTO llx_c_prescription_dose_unit (rowid, pos, code, label, active) VALUES
(1,  10,  'MG',   'mg', 1),
(2,  20,  'G',    'g', 1),
(3,  30,  'ML',   'ml', 1),
(4,  40,  'PIAN', '片', 1),
(5,  50,  'LI',   '粒', 1),
(6,  60,  'DAI',  '袋', 1),
(7,  70,  'WAN',  '丸', 1),
(8,  80,  'ZHI',  '支', 1),
(9,  90,  'DI',   '滴', 1),
(10, 100, 'PEN',  '喷', 1),
(11, 110, 'TIE',  '贴', 1),
(12, 120, 'HE',   '盒', 1),
(13, 130, 'PING', '瓶', 1),
(14, 140, 'IU',   'IU', 1),
(15, 150, 'UG',   'μg', 1);
