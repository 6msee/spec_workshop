-- SPEC.md 5.2 assets
CREATE TABLE dbo.assets (
    id                UNIQUEIDENTIFIER NOT NULL DEFAULT NEWSEQUENTIALID() PRIMARY KEY,
    asset_code        NVARCHAR(50)  NOT NULL,
    building_id       UNIQUEIDENTIFIER NULL,
    floor             INT NULL,
    room              NVARCHAR(50)  NULL,
    category          NVARCHAR(30)  NOT NULL,
    brand             NVARCHAR(100) NULL,
    model             NVARCHAR(100) NULL,
    installed_at      DATE NULL,
    replacement_cost  DECIMAL(12,2) NULL,
    status            NVARCHAR(20)  NOT NULL DEFAULT 'active',
    CONSTRAINT UQ_assets_asset_code UNIQUE (asset_code),
    CONSTRAINT FK_assets_building FOREIGN KEY (building_id) REFERENCES dbo.buildings (id),
    CONSTRAINT CK_assets_category CHECK (category IN (
        'electrical', 'plumbing', 'hvac', 'structural', 'elevator',
        'landscape', 'safety', 'civil', 'furniture', 'other'
    )),
    CONSTRAINT CK_assets_status CHECK (status IN ('active', 'retired'))
);
