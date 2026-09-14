#
# tx_filefix_quarantine — file cleanup quarantine queue
#
CREATE TABLE tx_filefix_quarantine (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    storage_uid int(11) DEFAULT 0 NOT NULL,
    file_uid int(11) DEFAULT 0 NOT NULL,
    identifier varchar(512) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    mime_type varchar(100) DEFAULT '' NOT NULL,
    size bigint(20) DEFAULT 0 NOT NULL,
    sha1 varchar(40) DEFAULT '' NOT NULL,
    mtime int(11) DEFAULT 0 NOT NULL,
    absolute_path_snapshot varchar(1024) DEFAULT '' NOT NULL,
    quarantine_identifier varchar(1024) DEFAULT '' NOT NULL,
    reason varchar(64) DEFAULT '' NOT NULL,
    status varchar(32) DEFAULT 'candidate' NOT NULL,
    scan_id varchar(64) DEFAULT '' NOT NULL,
    created_at int(11) DEFAULT 0 NOT NULL,
    updated_at int(11) DEFAULT 0 NOT NULL,
    last_checked_at int(11) DEFAULT 0 NOT NULL,
    quarantined_at int(11) DEFAULT 0 NOT NULL,
    flushed_at int(11) DEFAULT 0 NOT NULL,
    restored_at int(11) DEFAULT 0 NOT NULL,
    metadata_json mediumtext,
    error_message text,

    PRIMARY KEY (uid),
    KEY status_idx (status),
    KEY reason_idx (reason),
    KEY scan_id_idx (scan_id),
    KEY storage_file_idx (storage_uid, file_uid)
);

#
# tx_filefix_log — action log for file cleanup and quarantine operations
#
CREATE TABLE tx_filefix_log (
    uid int(11) NOT NULL auto_increment,
    created_at int(11) DEFAULT 0 NOT NULL,
    action varchar(40) DEFAULT '' NOT NULL,
    identifier varchar(512) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    storage_uid int(11) DEFAULT 0 NOT NULL,
    file_uid int(11) DEFAULT 0 NOT NULL,
    be_user_uid int(11) DEFAULT 0 NOT NULL,
    be_user_name varchar(255) DEFAULT '' NOT NULL,
    details text,

    PRIMARY KEY (uid),
    KEY created_at_idx (created_at),
    KEY action_idx (action)
);
