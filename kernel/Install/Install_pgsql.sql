DROP TABLE IF EXISTS __PREFIX__order_option;
DROP TABLE IF EXISTS __PREFIX__order;
DROP TABLE IF EXISTS __PREFIX__coupon;
DROP TABLE IF EXISTS __PREFIX__card;
DROP TABLE IF EXISTS __PREFIX__user_commodity;
DROP TABLE IF EXISTS __PREFIX__user_category;
DROP TABLE IF EXISTS __PREFIX__commodity;
DROP TABLE IF EXISTS __PREFIX__category;
DROP TABLE IF EXISTS __PREFIX__bill;
DROP TABLE IF EXISTS __PREFIX__cash;
DROP TABLE IF EXISTS __PREFIX__user_recharge;
DROP TABLE IF EXISTS __PREFIX__business;
DROP TABLE IF EXISTS __PREFIX__business_level;
DROP TABLE IF EXISTS __PREFIX__user_group;
DROP TABLE IF EXISTS __PREFIX__pay;
DROP TABLE IF EXISTS __PREFIX__shared;
DROP TABLE IF EXISTS __PREFIX__manage_log;
DROP TABLE IF EXISTS __PREFIX__manage;
DROP TABLE IF EXISTS __PREFIX__config;
DROP TABLE IF EXISTS __PREFIX__upload;
DROP TABLE IF EXISTS __PREFIX__commodity_group;
DROP TABLE IF EXISTS __PREFIX__user;

CREATE TABLE __PREFIX__user (
    id SERIAL NOT NULL,
    username varchar(32) NOT NULL,
    email varchar(128) NULL DEFAULT NULL,
    phone varchar(16) NULL DEFAULT NULL,
    qq varchar(16) NULL DEFAULT NULL,
    password varchar(64) NOT NULL,
    salt varchar(32) NOT NULL,
    app_key varchar(32) NOT NULL,
    avatar varchar(255) NULL DEFAULT NULL,
    balance decimal(14, 2) NOT NULL DEFAULT 0.00,
    coin decimal(14, 2) NOT NULL DEFAULT 0.00,
    integral INTEGER NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    login_time TIMESTAMP NULL DEFAULT NULL,
    last_login_time TIMESTAMP NULL DEFAULT NULL,
    login_ip varchar(128) NULL DEFAULT NULL,
    last_login_ip varchar(128) NULL DEFAULT NULL,
    pid INTEGER NULL DEFAULT 0,
    recharge decimal(14, 2) NOT NULL DEFAULT 0.00,
    total_coin decimal(14, 2) NOT NULL DEFAULT 0.00,
    status SMALLINT NOT NULL DEFAULT 0,
    business_level INTEGER NULL DEFAULT NULL,
    nicename varchar(10) NULL DEFAULT NULL,
    alipay varchar(64) NULL DEFAULT NULL,
    wechat varchar(255) NULL DEFAULT NULL,
    settlement SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__user_username_uq ON __PREFIX__user (username ASC);
CREATE UNIQUE INDEX __PREFIX__user_email_uq   ON __PREFIX__user (email ASC);
CREATE UNIQUE INDEX __PREFIX__user_phone_uq   ON __PREFIX__user (phone ASC);
CREATE INDEX __PREFIX__user_pid_idx           ON __PREFIX__user (pid ASC);
CREATE INDEX __PREFIX__user_business_level_idx ON __PREFIX__user (business_level ASC);
CREATE INDEX __PREFIX__user_coin_idx          ON __PREFIX__user (coin ASC);

CREATE TABLE __PREFIX__business_level (
    id SERIAL NOT NULL,
    name varchar(32) NOT NULL,
    icon varchar(255) NULL DEFAULT NULL,
    cost decimal(4, 2) NOT NULL DEFAULT 0.00,
    accrual decimal(4, 2) NOT NULL DEFAULT 0.00,
    substation SMALLINT NOT NULL DEFAULT 0,
    top_domain SMALLINT NOT NULL DEFAULT 0,
    price decimal(10, 2) NOT NULL DEFAULT 0.00,
    supplier SMALLINT NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
);

INSERT INTO __PREFIX__business_level
VALUES
(1, '体验版', '/assets/static/images/business/v1.png', 0.30, 0.10, 1, 0, 188.00, 1),
(3, '普通版', '/assets/static/images/business/v2.png', 0.25, 0.15, 1, 0, 288.00, 1),
(4, '专业版', '/assets/static/images/business/v3.png', 0.20, 0.20, 1, 1, 388.00, 1);


CREATE TABLE __PREFIX__business (
    id SERIAL NOT NULL,
    user_id INTEGER NOT NULL,
    shop_name varchar(32) NULL DEFAULT NULL,
    title varchar(32) NULL DEFAULT NULL,
    notice text NULL,
    service_qq varchar(16) NULL DEFAULT NULL,
    service_url varchar(255) NULL DEFAULT NULL,
    subdomain varchar(64) NULL DEFAULT NULL,
    topdomain varchar(64) NULL DEFAULT NULL,
    master_display SMALLINT NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__business_user_id_uq    ON __PREFIX__business (user_id ASC);
CREATE UNIQUE INDEX __PREFIX__business_subdomain_uq  ON __PREFIX__business (subdomain ASC);
CREATE UNIQUE INDEX __PREFIX__business_topdomain_uq  ON __PREFIX__business (topdomain ASC);


CREATE TABLE __PREFIX__category (
    id SERIAL NOT NULL,
    name varchar(255) NOT NULL,
    sort smallint NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    owner INTEGER NOT NULL DEFAULT 0,
    icon varchar(255) NULL DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    hide SMALLINT NOT NULL DEFAULT 0,
    user_level_config text NULL,
    pid INTEGER DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT ibfk_category_pid_in_id
        FOREIGN KEY (pid) REFERENCES __PREFIX__category(id)
        ON DELETE CASCADE
);

CREATE INDEX __PREFIX__category_owner_idx ON __PREFIX__category (owner ASC);
CREATE INDEX __PREFIX__category_pid_idx   ON __PREFIX__category (pid);
CREATE INDEX __PREFIX__category_sort_idx  ON __PREFIX__category (sort ASC);

INSERT INTO __PREFIX__category
VALUES (1, 'DEMO', 1, '2021-11-26 17:59:45', 0, '/favicon.ico', 1, 0, NULL, NULL);


CREATE TABLE __PREFIX__commodity (
    id SERIAL NOT NULL,
    category_id INTEGER NOT NULL,
    name varchar(255) NOT NULL,
    description text NULL,
    cover varchar(255) NULL DEFAULT NULL,
    factory_price decimal(10, 2) NOT NULL DEFAULT 0.00,
    price decimal(10, 2) NOT NULL DEFAULT 0.00,
    user_price decimal(10, 2) NOT NULL DEFAULT 0.00,
    status SMALLINT NOT NULL DEFAULT 0,
    owner INTEGER NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    api_status SMALLINT NOT NULL DEFAULT 0,
    code varchar(64) NOT NULL,
    delivery_way SMALLINT NOT NULL DEFAULT 0,
    delivery_auto_mode SMALLINT NOT NULL DEFAULT 0,
    delivery_message varchar(255) NULL DEFAULT NULL,
    contact_type SMALLINT NOT NULL DEFAULT 0,
    password_status SMALLINT NOT NULL DEFAULT 0,
    sort smallint NOT NULL DEFAULT 0,
    coupon SMALLINT NOT NULL DEFAULT 0,
    shared_id INTEGER NULL DEFAULT NULL,
    shared_code varchar(64) NULL DEFAULT NULL,
    shared_premium decimal(10, 2) NULL DEFAULT 0.00,
    shared_stock json DEFAULT NULL,
    stock INTEGER DEFAULT NULL,
    shared_premium_type SMALLINT NULL DEFAULT 0,
    seckill_status SMALLINT NOT NULL DEFAULT 0,
    seckill_start_time TIMESTAMP NULL DEFAULT NULL,
    seckill_end_time TIMESTAMP NULL DEFAULT NULL,
    draft_status SMALLINT NOT NULL DEFAULT 0,
    draft_premium decimal(10, 2) NULL DEFAULT 0.00,
    inventory_hidden SMALLINT NOT NULL DEFAULT 0,
    leave_message varchar(255) NULL DEFAULT NULL,
    recommend SMALLINT NULL DEFAULT 0,
    send_email SMALLINT NOT NULL DEFAULT 0,
    only_user SMALLINT NOT NULL DEFAULT 0,
    purchase_count INTEGER NOT NULL DEFAULT 0,
    widget text NULL,
    level_price text NULL,
    level_disable SMALLINT NOT NULL DEFAULT 0,
    minimum INTEGER NOT NULL DEFAULT 0,
    maximum INTEGER NOT NULL DEFAULT 0,
    shared_sync SMALLINT NOT NULL DEFAULT 0,
    config text NULL,
    hide SMALLINT NOT NULL DEFAULT 0,
    inventory_sync SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__commodity_code_uq        ON __PREFIX__commodity (code ASC);
CREATE INDEX __PREFIX__commodity_owner_idx             ON __PREFIX__commodity (owner ASC);
CREATE INDEX __PREFIX__commodity_status_idx            ON __PREFIX__commodity (status ASC);
CREATE INDEX __PREFIX__commodity_sort_idx              ON __PREFIX__commodity (sort ASC);
CREATE INDEX __PREFIX__commodity_category_id_idx       ON __PREFIX__commodity (category_id ASC);
CREATE INDEX __PREFIX__commodity_shared_id_idx         ON __PREFIX__commodity (shared_id ASC);
CREATE INDEX __PREFIX__commodity_seckill_status_idx    ON __PREFIX__commodity (seckill_status ASC);
CREATE INDEX __PREFIX__commodity_api_status_idx        ON __PREFIX__commodity (api_status ASC);
CREATE INDEX __PREFIX__commodity_recommend_idx         ON __PREFIX__commodity (recommend ASC);

INSERT INTO __PREFIX__commodity
VALUES (
    1,
    1,
    'DEMO',
    '<p>该商品是演示商品</p>',
    '/favicon.ico',
    0.00,
    1.00,
    0.90,
    1,
    0,
    '2021-11-26 18:01:30',
    1,
    '8AE80574F3CA98BE',
    1,
    0,
    '',
    0,
    0,
    1,
    1,
    NULL,
    '',
    0.00,
    NULL,
    999999,
    0,
    0,
    NULL,
    NULL,
    0,
    0.00,
    0,
    NULL,
    0,
    0,
    0,
    0,
    NULL,
    NULL,
    0,
    0,
    0,
    0,
    NULL,
    0,
    0
);


CREATE TABLE __PREFIX__card (
    id SERIAL NOT NULL,
    owner INTEGER NOT NULL DEFAULT 0,
    commodity_id INTEGER NOT NULL,
    draft varchar(255) NULL DEFAULT NULL,
    secret varchar(760) NOT NULL,
    create_time TIMESTAMP NOT NULL,
    purchase_time TIMESTAMP NULL DEFAULT NULL,
    order_id INTEGER NULL DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    note varchar(64) NULL DEFAULT NULL,
    race varchar(32) NULL DEFAULT NULL,
    sku json DEFAULT NULL,
    draft_premium decimal(10,2) DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__card_ibfk_1
        FOREIGN KEY (commodity_id) REFERENCES __PREFIX__commodity (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE INDEX __PREFIX__card_owner_idx        ON __PREFIX__card (owner ASC);
CREATE INDEX __PREFIX__card_commodity_id_idx ON __PREFIX__card (commodity_id ASC);
CREATE INDEX __PREFIX__card_order_id_idx     ON __PREFIX__card (order_id ASC);
CREATE INDEX __PREFIX__card_secret_idx       ON __PREFIX__card (secret ASC);
CREATE INDEX __PREFIX__card_status_idx       ON __PREFIX__card (status ASC);
CREATE INDEX __PREFIX__card_note_idx         ON __PREFIX__card (note ASC);
CREATE INDEX __PREFIX__card_race_idx         ON __PREFIX__card (race ASC);


CREATE TABLE __PREFIX__config (
    id SERIAL NOT NULL,
    key varchar(128) NOT NULL,
    value text NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__config_key_unique UNIQUE (key)
);

INSERT INTO __PREFIX__config VALUES (1,  'shop_name', '异次元店铺');
INSERT INTO __PREFIX__config VALUES (2,  'title', '异次元店铺 - 最适合你的个人店铺系统！');
INSERT INTO __PREFIX__config VALUES (3,  'description', '');
INSERT INTO __PREFIX__config VALUES (4,  'keywords', '');
INSERT INTO __PREFIX__config VALUES (14, 'user_theme', 'Cartoon');
INSERT INTO __PREFIX__config VALUES (5,  'registered_state', '1');
INSERT INTO __PREFIX__config VALUES (6,  'registered_type', '0');
INSERT INTO __PREFIX__config VALUES (7,  'registered_verification', '1');
INSERT INTO __PREFIX__config VALUES (8,  'registered_phone_verification', '0');
INSERT INTO __PREFIX__config VALUES (9,  'registered_email_verification', '0');
INSERT INTO __PREFIX__config VALUES (10, 'sms_config', '{"accessKeyId":"","accessKeySecret":"","signName":"","templateCode":""}');
INSERT INTO __PREFIX__config VALUES (11, 'email_config', '{"smtp":"","port":"","username":"","password":""}');
INSERT INTO __PREFIX__config VALUES (12, 'login_verification', '1');
INSERT INTO __PREFIX__config VALUES (13, 'forget_type', '0');
INSERT INTO __PREFIX__config VALUES (15, 'notice', '<p><b><font color="#f9963b">本程序为开源程序，使用者造成的一切法律后果与作者无关。</font></b></p>');
INSERT INTO __PREFIX__config VALUES (16, 'trade_verification', '1');
INSERT INTO __PREFIX__config VALUES (17, 'recharge_welfare', '0');
INSERT INTO __PREFIX__config VALUES (18, 'recharge_welfare_config', '');
INSERT INTO __PREFIX__config VALUES (19, 'promote_rebate_v1', '0.1');
INSERT INTO __PREFIX__config VALUES (20, 'promote_rebate_v2', '0.2');
INSERT INTO __PREFIX__config VALUES (21, 'promote_rebate_v3', '0.3');
INSERT INTO __PREFIX__config VALUES (22, 'substation_display', '1');
INSERT INTO __PREFIX__config VALUES (24, 'domain', '');
INSERT INTO __PREFIX__config VALUES (25, 'service_qq', '');
INSERT INTO __PREFIX__config VALUES (26, 'service_url', '');
INSERT INTO __PREFIX__config VALUES (27, 'cash_type_alipay', '1');
INSERT INTO __PREFIX__config VALUES (28, 'cash_type_wechat', '1');
INSERT INTO __PREFIX__config VALUES (29, 'cash_cost', '5');
INSERT INTO __PREFIX__config VALUES (30, 'cash_min', '100');
INSERT INTO __PREFIX__config VALUES (31, 'cname', '');
INSERT INTO __PREFIX__config VALUES (32, 'background_url', '/assets/admin/images/login/bg.jpg');
INSERT INTO __PREFIX__config VALUES (33, 'default_category', '0');
INSERT INTO __PREFIX__config VALUES (34, 'substation_display_list', '[]');
INSERT INTO __PREFIX__config VALUES (35, 'closed', '0');
INSERT INTO __PREFIX__config VALUES (36, 'closed_message', '我们正在升级，请耐心等待完成。');
INSERT INTO __PREFIX__config VALUES (37, 'recharge_min', '10');
INSERT INTO __PREFIX__config VALUES (38, 'recharge_max', '1000');
INSERT INTO __PREFIX__config VALUES (39, 'user_mobile_theme', '0');
INSERT INTO __PREFIX__config VALUES (40, 'commodity_recommend', '0');
INSERT INTO __PREFIX__config VALUES (41, 'commodity_name', '推荐');
INSERT INTO __PREFIX__config VALUES (42, 'background_mobile_url', '');
INSERT INTO __PREFIX__config VALUES (43, 'username_len', '6');
INSERT INTO __PREFIX__config VALUES (44, 'cash_type_balance', '0');
INSERT INTO __PREFIX__config VALUES (45, 'callback_domain', '');


CREATE TABLE __PREFIX__bill (
    id SERIAL NOT NULL,
    owner INTEGER NOT NULL,
    amount decimal(10, 2) NOT NULL,
    balance decimal(14, 2) NOT NULL,
    type SMALLINT NOT NULL,
    currency SMALLINT NOT NULL DEFAULT 0,
    log varchar(64) NOT NULL,
    create_time TIMESTAMP NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__bill_ibfk_1
        FOREIGN KEY (owner) REFERENCES __PREFIX__user (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE INDEX __PREFIX__bill_owner_idx ON __PREFIX__bill (owner ASC);
CREATE INDEX __PREFIX__bill_type_idx  ON __PREFIX__bill (type ASC);

CREATE TABLE __PREFIX__cash (
    id SERIAL NOT NULL,
    user_id INTEGER NOT NULL,
    amount decimal(14, 2) NOT NULL,
    type SMALLINT NOT NULL DEFAULT 0,
    card SMALLINT NOT NULL,
    create_time TIMESTAMP NOT NULL,
    arrive_time TIMESTAMP NULL DEFAULT NULL,
    cost decimal(10, 2) NOT NULL DEFAULT 0.00,
    status SMALLINT NOT NULL,
    message varchar(64) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__cash_ibfk_1
        FOREIGN KEY (user_id) REFERENCES __PREFIX__user (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE INDEX __PREFIX__cash_user_id_idx ON __PREFIX__cash (user_id ASC);
CREATE INDEX __PREFIX__cash_type_idx    ON __PREFIX__cash (type ASC);
CREATE INDEX __PREFIX__cash_message_idx ON __PREFIX__cash (message ASC);


CREATE TABLE __PREFIX__coupon (
    id SERIAL NOT NULL,
    code varchar(32) NOT NULL,
    commodity_id INTEGER NOT NULL,
    owner INTEGER NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    expire_time TIMESTAMP NULL DEFAULT NULL,
    service_time TIMESTAMP NULL DEFAULT NULL,
    money decimal(10, 2) NOT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    trade_no char(22) NULL DEFAULT NULL,
    note varchar(32) NULL DEFAULT NULL,
    mode SMALLINT NULL DEFAULT 0,
    category_id INTEGER NULL DEFAULT 0,
    life INTEGER NOT NULL DEFAULT 1,
    use_life INTEGER NOT NULL DEFAULT 0,
    race varchar(32) NULL DEFAULT NULL,
    sku json DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__coupon_code_uq        ON __PREFIX__coupon (code ASC);
CREATE INDEX __PREFIX__coupon_commodity_id_idx      ON __PREFIX__coupon (commodity_id ASC);
CREATE INDEX __PREFIX__coupon_owner_idx             ON __PREFIX__coupon (owner ASC);
CREATE INDEX __PREFIX__coupon_create_time_idx       ON __PREFIX__coupon (create_time ASC);
CREATE INDEX __PREFIX__coupon_money_idx             ON __PREFIX__coupon (money ASC);
CREATE INDEX __PREFIX__coupon_status_idx            ON __PREFIX__coupon (status ASC);
CREATE INDEX __PREFIX__coupon_trade_no_idx          ON __PREFIX__coupon (trade_no ASC);
CREATE INDEX __PREFIX__coupon_note_idx              ON __PREFIX__coupon (note ASC);
CREATE INDEX __PREFIX__coupon_race_idx              ON __PREFIX__coupon (race ASC);


CREATE TABLE __PREFIX__manage (
    id SERIAL NOT NULL,
    email varchar(64) NOT NULL,
    password varchar(64) NOT NULL,
    security_password varchar(64) NULL DEFAULT NULL,
    nickname varchar(32) NULL DEFAULT NULL,
    salt varchar(32) NOT NULL,
    avatar varchar(128) NULL DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    type SMALLINT NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    login_time TIMESTAMP NULL DEFAULT NULL,
    last_login_time TIMESTAMP NULL DEFAULT NULL,
    login_ip varchar(128) NULL DEFAULT NULL,
    last_login_ip varchar(128) NULL DEFAULT NULL,
    note varchar(255) NULL DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__manage_email_uq ON __PREFIX__manage (email ASC);

INSERT INTO __PREFIX__manage
VALUES (
    1,
    '__MANAGE_EMAIL__',
    '__MANAGE_PASSWORD__',
    NULL,
    '__MANAGE_NICKNAME__',
    '__MANAGE_SALT__',
    '/favicon.ico',
    1,
    0,
    '1997-01-01 00:00:00',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL
);

CREATE TABLE __PREFIX__order (
    id SERIAL NOT NULL,
    owner INTEGER NOT NULL DEFAULT 0,
    user_id INTEGER NOT NULL DEFAULT 0,
    trade_no char(19) NOT NULL,
    amount decimal(10, 2) NOT NULL,
    commodity_id INTEGER NOT NULL,
    card_id INTEGER NULL DEFAULT NULL,
    card_num INTEGER NOT NULL DEFAULT 0,
    pay_id INTEGER NOT NULL,
    create_time TIMESTAMP NOT NULL,
    create_ip varchar(64) NOT NULL,
    create_device SMALLINT NOT NULL,
    pay_time TIMESTAMP NULL DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    secret text NULL,
    password varchar(32) NULL DEFAULT NULL,
    contact varchar(32) NULL DEFAULT NULL,
    delivery_status SMALLINT NOT NULL DEFAULT 0,
    pay_url varchar(1024) NULL DEFAULT NULL,
    coupon_id INTEGER NULL DEFAULT NULL,
    cost decimal(10, 2) NOT NULL DEFAULT 0.00,
    "from" INTEGER NULL DEFAULT NULL,
    premium decimal(10, 2) NULL DEFAULT 0.00,
    widget text NULL,
    rent decimal(10, 2) NOT NULL DEFAULT 0.00,
    race varchar(32) NULL DEFAULT NULL,
    rebate decimal(10, 2) NULL DEFAULT 0.00,
    pay_cost decimal(10, 2) NULL DEFAULT 0.00,
    sku json DEFAULT NULL,
    divide_amount decimal(10,2) DEFAULT NULL,
    substation_user_id INTEGER DEFAULT NULL,
    request_no char(19),
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__order_trade_no_uq        ON __PREFIX__order (trade_no ASC);
CREATE UNIQUE INDEX __PREFIX__order_request_no_uq      ON __PREFIX__order (request_no ASC);
CREATE INDEX __PREFIX__order_commodity_id_idx          ON __PREFIX__order (commodity_id ASC);
CREATE INDEX __PREFIX__order_pay_id_idx                ON __PREFIX__order (pay_id ASC);
CREATE INDEX __PREFIX__order_contact_idx               ON __PREFIX__order (contact ASC);
CREATE INDEX __PREFIX__order_create_ip_idx             ON __PREFIX__order (create_ip ASC);
CREATE INDEX __PREFIX__order_owner_idx                 ON __PREFIX__order (owner ASC);
CREATE INDEX __PREFIX__order_from_idx                  ON __PREFIX__order ("from" ASC);
CREATE INDEX __PREFIX__order_user_id_idx               ON __PREFIX__order (user_id ASC);
CREATE INDEX __PREFIX__order_card_id_idx               ON __PREFIX__order (card_id ASC);
CREATE INDEX __PREFIX__order_create_time_idx           ON __PREFIX__order (create_time ASC);
CREATE INDEX __PREFIX__order_delivery_status_idx       ON __PREFIX__order (delivery_status ASC);
CREATE INDEX __PREFIX__order_substation_user_id_idx    ON __PREFIX__order (substation_user_id);
CREATE INDEX __PREFIX__order_coupon_id_idx             ON __PREFIX__order (coupon_id ASC);

CREATE TABLE __PREFIX__order_option (
    id SERIAL NOT NULL,
    order_id INTEGER NOT NULL,
    option text NULL,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__order_option_ibfk_1
        FOREIGN KEY (order_id) REFERENCES __PREFIX__order (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE UNIQUE INDEX __PREFIX__order_option_order_id_uq ON __PREFIX__order_option (order_id ASC);


CREATE TABLE __PREFIX__pay (
    id SERIAL NOT NULL,
    name varchar(16) NOT NULL,
    icon varchar(255) NULL DEFAULT NULL,
    code varchar(32) NOT NULL,
    commodity SMALLINT NOT NULL DEFAULT 0,
    recharge SMALLINT NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    handle varchar(64) NOT NULL,
    sort smallint NOT NULL DEFAULT 0,
    equipment SMALLINT NOT NULL DEFAULT 0,
    cost decimal(10, 3) NULL DEFAULT 0.000,
    cost_type SMALLINT NULL DEFAULT 0,
    PRIMARY KEY (id)
);

CREATE INDEX __PREFIX__pay_commodity_idx ON __PREFIX__pay (commodity ASC);
CREATE INDEX __PREFIX__pay_recharge_idx  ON __PREFIX__pay (recharge ASC);
CREATE INDEX __PREFIX__pay_sort_idx      ON __PREFIX__pay (sort ASC);
CREATE INDEX __PREFIX__pay_equipment_idx ON __PREFIX__pay (equipment ASC);

INSERT INTO __PREFIX__pay
VALUES
(1, '余额', '/assets/static/images/wallet.png', '#system', 1, 0, '1997-01-01 00:00:00', '#system', 999, 0, 0.000, 0),
(2, '支付宝', '/assets/user/images/cash/alipay.png', 'alipay', 1, 1, '1997-01-01 00:00:00', 'Epay', 1, 0, 0.000, 0);


CREATE TABLE __PREFIX__shared (
    id SERIAL NOT NULL,
    type SMALLINT NOT NULL DEFAULT 0,
    name varchar(128) NOT NULL,
    domain varchar(128) NOT NULL,
    app_id varchar(32) NOT NULL,
    app_key varchar(64) NOT NULL,
    create_time TIMESTAMP NOT NULL,
    balance decimal(14, 2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__shared_domain_uq ON __PREFIX__shared (domain ASC);


CREATE TABLE __PREFIX__user_category (
    id SERIAL NOT NULL,
    user_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    name varchar(255) NULL DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__user_category_ibfk_1
        FOREIGN KEY (user_id) REFERENCES __PREFIX__user (id)
        ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT __PREFIX__user_category_ibfk_2
        FOREIGN KEY (category_id) REFERENCES __PREFIX__category (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE UNIQUE INDEX __PREFIX__user_category_user_category_uq
    ON __PREFIX__user_category (user_id ASC, category_id ASC);
CREATE INDEX __PREFIX__user_category_status_idx      ON __PREFIX__user_category (status ASC);
CREATE INDEX __PREFIX__user_category_category_id_idx ON __PREFIX__user_category (category_id ASC);
CREATE INDEX __PREFIX__user_category_user_id_idx     ON __PREFIX__user_category (user_id ASC);



CREATE TABLE __PREFIX__user_commodity (
    id SERIAL NOT NULL,
    user_id INTEGER NOT NULL,
    commodity_id INTEGER NOT NULL,
    premium decimal(10, 2) NULL DEFAULT 0.00,
    name varchar(255) NULL DEFAULT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__user_commodity_ibfk_1
        FOREIGN KEY (user_id) REFERENCES __PREFIX__user (id)
        ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT __PREFIX__user_commodity_ibfk_2
        FOREIGN KEY (commodity_id) REFERENCES __PREFIX__commodity (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE UNIQUE INDEX __PREFIX__user_commodity_user_commodity_uq
    ON __PREFIX__user_commodity (user_id ASC, commodity_id ASC);
CREATE INDEX __PREFIX__user_commodity_commodity_id_idx ON __PREFIX__user_commodity (commodity_id ASC);
CREATE INDEX __PREFIX__user_commodity_user_id_idx      ON __PREFIX__user_commodity (user_id ASC);
CREATE INDEX __PREFIX__user_commodity_status_idx       ON __PREFIX__user_commodity (status ASC);



CREATE TABLE __PREFIX__user_group (
    id SERIAL NOT NULL,
    name varchar(32) NOT NULL,
    icon varchar(128) NULL DEFAULT NULL,
    discount_config TEXT DEFAULT NULL,
    cost decimal(4, 2) NOT NULL DEFAULT 0.00,
    recharge decimal(14, 2) NOT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__user_group_recharge_uq ON __PREFIX__user_group (recharge ASC);

INSERT INTO __PREFIX__user_group
VALUES
(1, '一贫如洗', '/assets/static/images/group/ic_user level_1.png', NULL, 0.30, 0.00),
(2, '小康之家', '/assets/static/images/group/ic_user level_2.png', NULL, 0.25, 50.00),
(3, '腰缠万贯', '/assets/static/images/group/ic_user level_3.png', NULL, 0.20, 100.00),
(4, '富甲一方', '/assets/static/images/group/ic_user level_4.png', NULL, 0.15, 200.00),
(5, '富可敌国', '/assets/static/images/group/ic_user level_5.png', NULL, 0.10, 300.00),
(6, '至尊',     '/assets/static/images/group/ic_user level_6.png', NULL, 0.05, 500.00);



CREATE TABLE __PREFIX__user_recharge (
    id SERIAL NOT NULL,
    trade_no char(22) NOT NULL,
    user_id INTEGER NOT NULL,
    amount decimal(10, 2) NOT NULL,
    pay_id INTEGER NOT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    create_time TIMESTAMP NOT NULL,
    create_ip varchar(64) NOT NULL,
    pay_url varchar(255) NULL DEFAULT NULL,
    pay_time TIMESTAMP NULL DEFAULT NULL,
    option text NULL,
    PRIMARY KEY (id),
    CONSTRAINT __PREFIX__user_recharge_ibfk_1
        FOREIGN KEY (user_id) REFERENCES __PREFIX__user (id)
        ON DELETE CASCADE ON UPDATE RESTRICT
);

CREATE UNIQUE INDEX __PREFIX__user_recharge_trade_no_uq ON __PREFIX__user_recharge (trade_no ASC);
CREATE INDEX __PREFIX__user_recharge_user_id_idx         ON __PREFIX__user_recharge (user_id ASC);
CREATE INDEX __PREFIX__user_recharge_pay_id_idx          ON __PREFIX__user_recharge (pay_id ASC);
CREATE INDEX __PREFIX__user_recharge_status_idx          ON __PREFIX__user_recharge (status ASC);



CREATE TABLE __PREFIX__manage_log (
    id SERIAL NOT NULL,
    email varchar(64) NOT NULL,
    nickname varchar(32) NOT NULL,
    content varchar(128) NOT NULL,
    create_time TIMESTAMP NOT NULL,
    create_ip varchar(64) NOT NULL,
    ua varchar(255) NULL DEFAULT NULL,
    risk SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);

CREATE INDEX __PREFIX__manage_log_create_ip_idx   ON __PREFIX__manage_log (create_ip);
CREATE INDEX __PREFIX__manage_log_create_time_idx ON __PREFIX__manage_log (create_time);
CREATE INDEX __PREFIX__manage_log_risk_idx        ON __PREFIX__manage_log (risk);
CREATE INDEX __PREFIX__manage_log_email_idx       ON __PREFIX__manage_log (email);
CREATE INDEX __PREFIX__manage_log_nickname_idx    ON __PREFIX__manage_log (nickname);
CREATE INDEX __PREFIX__manage_log_content_idx     ON __PREFIX__manage_log (content);



CREATE TABLE __PREFIX__commodity_group (
    id SERIAL NOT NULL,
    name varchar(32) NOT NULL,
    commodity_list json DEFAULT NULL,
    PRIMARY KEY (id)
);


CREATE TABLE __PREFIX__upload (
    id SERIAL NOT NULL,
    user_id INTEGER DEFAULT NULL,
    hash varchar(32) NOT NULL,
    type varchar(8) NOT NULL,
    path varchar(255) NOT NULL,
    create_time TIMESTAMP NOT NULL,
    note varchar(32) DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX __PREFIX__upload_hash_uq      ON __PREFIX__upload (hash);
CREATE INDEX __PREFIX__upload_user_id_idx         ON __PREFIX__upload (user_id);
CREATE INDEX __PREFIX__upload_type_idx            ON __PREFIX__upload (type);
CREATE INDEX __PREFIX__upload_create_time_idx     ON __PREFIX__upload (create_time);
CREATE INDEX __PREFIX__upload_note_idx            ON __PREFIX__upload (note);


SELECT setval('__PREFIX__business_level_id_seq', (SELECT MAX(id) FROM __PREFIX__business_level));
SELECT setval('__PREFIX__category_id_seq', (SELECT MAX(id) FROM __PREFIX__category));
SELECT setval('__PREFIX__commodity_id_seq', (SELECT MAX(id) FROM __PREFIX__commodity));
SELECT setval('__PREFIX__config_id_seq', (SELECT MAX(id) FROM __PREFIX__config));
SELECT setval('__PREFIX__manage_id_seq', (SELECT MAX(id) FROM __PREFIX__manage));
SELECT setval('__PREFIX__pay_id_seq', (SELECT MAX(id) FROM __PREFIX__pay));
SELECT setval('__PREFIX__user_group_id_seq', (SELECT MAX(id) FROM __PREFIX__user_group));
SELECT setval('__PREFIX__user_id_seq', (SELECT MAX(id) FROM __PREFIX__user));
