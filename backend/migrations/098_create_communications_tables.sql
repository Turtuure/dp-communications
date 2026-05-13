-- 098_create_communications_tables.sql

-- 1. Tenant-asetukset (SMTP + cron + brand)
CREATE TABLE tenant_communication_settings (
    tenant_id                    CHAR(36) PRIMARY KEY,
    smtp_dsn_encrypted           TEXT             NULL,
    mail_from_address            VARCHAR(255)     NULL,
    mail_display_name            VARCHAR(255)     NULL,
    mail_reply_to                VARCHAR(255)     NULL,
    smtp_test_succeeded_at       DATETIME(3)      NULL,
    reminder_pre_due_days        INT              NOT NULL DEFAULT 7,
    reminder_post_due_days       JSON             NOT NULL,
    lapse_warning_days_before    INT              NOT NULL DEFAULT 30,
    brand_logo_url               VARCHAR(500)     NULL,
    brand_primary_color          VARCHAR(7)       NULL,
    brand_footer_address         TEXT             NULL,
    updated_at                   DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_tcs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- 2. Meetings (thin payload)
CREATE TABLE meetings (
    id                CHAR(36)     PRIMARY KEY,
    tenant_id         CHAR(36)     NOT NULL,
    type              ENUM('annual_meeting','extraordinary','board_meeting') NOT NULL,
    starts_at         DATETIME(3)  NOT NULL,
    location          VARCHAR(500) NULL,
    remote_url        VARCHAR(500) NULL,
    title_i18n        JSON         NOT NULL,
    agenda_items_i18n JSON         NOT NULL,
    document_urls     JSON         NOT NULL,
    status            ENUM('draft','scheduled','invites_sent','completed') NOT NULL DEFAULT 'draft',
    created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        CHAR(36)     NOT NULL,
    INDEX idx_meetings_tenant_starts (tenant_id, starts_at),
    INDEX idx_meetings_tenant_status (tenant_id, status),
    CONSTRAINT fk_meetings_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_meetings_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 3. Mail-outbox (lähetysjono + audit)
CREATE TABLE mail_outbox (
    id                    CHAR(36)     PRIMARY KEY,
    tenant_id             CHAR(36)     NOT NULL,
    kind                  ENUM('meeting_invitation','payment_reminder','membership_approved','group_message','newsletter') NOT NULL,
    category              ENUM('transactional','operational','marketing') NOT NULL,
    recipient_email       VARCHAR(255) NOT NULL,
    recipient_user_id     CHAR(36)     NULL,
    locale                ENUM('fi_FI','en_GB','sw_TZ') NOT NULL,
    subject               TEXT         NOT NULL,
    body_html             MEDIUMTEXT   NOT NULL,
    body_text             MEDIUMTEXT   NOT NULL,
    payload_vars          JSON         NOT NULL,
    payload_meeting_id    CHAR(36)     NULL,
    payload_invoice_id    CHAR(36)     NULL,
    payload_newsletter_id CHAR(36)     NULL,
    status                ENUM('queued','sending','sent','failed','bounced','suppressed') NOT NULL DEFAULT 'queued',
    attempt_count         INT          NOT NULL DEFAULT 0,
    last_error            TEXT         NULL,
    queued_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    sent_at               DATETIME(3)  NULL,
    queued_by             CHAR(36)     NOT NULL,
    pseudonymized_at      DATETIME(3)  NULL,
    INDEX idx_outbox_drain   (status, queued_at),
    INDEX idx_outbox_tenant  (tenant_id, queued_at DESC),
    INDEX idx_outbox_invoice (payload_invoice_id),
    INDEX idx_outbox_meeting (payload_meeting_id),
    INDEX idx_outbox_retention (queued_at, pseudonymized_at),
    CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id)         REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_outbox_user   FOREIGN KEY (recipient_user_id) REFERENCES users(id)
);

-- 4. Mail-suppressions (hard-bounce + complaint + manual block)
CREATE TABLE mail_suppressions (
    tenant_id           CHAR(36)     NOT NULL,
    email_address       VARCHAR(255) NOT NULL,
    reason              ENUM('hard_bounce','complaint','manual_block') NOT NULL,
    smtp_response_code  VARCHAR(16)  NULL,
    suppressed_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    suppressed_by       CHAR(36)     NULL,
    PRIMARY KEY (tenant_id, email_address),
    INDEX idx_suppression_tenant (tenant_id, suppressed_at DESC),
    CONSTRAINT fk_suppr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- 5. Template-overrides (4 strikt-tyypin admin-stringit, ei uutiskirjettä)
CREATE TABLE mail_template_overrides (
    tenant_id  CHAR(36) NOT NULL,
    kind       ENUM('meeting_invitation','payment_reminder','membership_approved','group_message') NOT NULL,
    locale     ENUM('fi_FI','en_GB','sw_TZ') NOT NULL,
    overrides  JSON     NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    updated_by CHAR(36) NOT NULL,
    PRIMARY KEY (tenant_id, kind, locale),
    CONSTRAINT fk_mto_tenant FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_mto_user   FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- 6. Newsletter-drafts (vapaa blokki-rakenne)
CREATE TABLE newsletter_drafts (
    id              CHAR(36) PRIMARY KEY,
    tenant_id       CHAR(36) NOT NULL,
    internal_name   VARCHAR(255) NOT NULL,
    subject_i18n    JSON     NOT NULL,
    blocks_i18n     JSON     NOT NULL,
    audience_filter JSON     NOT NULL,
    status          ENUM('draft','scheduled','sent') NOT NULL DEFAULT 'draft',
    sent_at         DATETIME(3) NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      CHAR(36) NOT NULL,
    updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_newsletter_tenant_status (tenant_id, status),
    CONSTRAINT fk_nl_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_nl_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 7. User-communication-preferences
CREATE TABLE user_communication_preferences (
    user_id    CHAR(36) NOT NULL,
    tenant_id  CHAR(36) NOT NULL,
    category   ENUM('transactional','operational','marketing') NOT NULL,
    opted_in   BOOLEAN  NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, tenant_id, category),
    INDEX idx_ucp_tenant (tenant_id, category, opted_in),
    CONSTRAINT fk_ucp_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ucp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Seed: default-preferences olemassaoleville jäsenille
INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
SELECT u.id, ut.tenant_id, 'transactional', TRUE
FROM users u JOIN user_tenants ut ON ut.user_id = u.id
ON DUPLICATE KEY UPDATE opted_in = opted_in;

INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
SELECT u.id, ut.tenant_id, 'operational', TRUE
FROM users u JOIN user_tenants ut ON ut.user_id = u.id
ON DUPLICATE KEY UPDATE opted_in = opted_in;

INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
SELECT u.id, ut.tenant_id, 'marketing', FALSE
FROM users u JOIN user_tenants ut ON ut.user_id = u.id
ON DUPLICATE KEY UPDATE opted_in = opted_in;

-- Seed: default-asetukset jokaiselle olemassa olevalle tenantille
INSERT INTO tenant_communication_settings (tenant_id, reminder_post_due_days)
SELECT id, JSON_ARRAY(14, 30) FROM tenants
ON DUPLICATE KEY UPDATE tenant_id = tenant_id;
