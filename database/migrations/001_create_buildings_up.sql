-- SPEC.md 5.1 buildings
CREATE TABLE dbo.buildings (
    id              UNIQUEIDENTIFIER NOT NULL DEFAULT NEWSEQUENTIALID() PRIMARY KEY,
    code            NVARCHAR(20)  NOT NULL,
    name_th         NVARCHAR(200) NOT NULL,
    zone            NVARCHAR(50)  NULL,
    lat             DECIMAL(10,7) NULL,
    lng             DECIMAL(10,7) NULL,
    floors          INT NULL,
    gross_area_sqm  DECIMAL(12,2) NULL,
    is_active       BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_buildings_code UNIQUE (code)
);
