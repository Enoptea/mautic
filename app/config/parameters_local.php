<?php

return $parameters = [
    'api_enabled'           => true,
    'db_driver'             => 'pdo_mysql',
    'db_host'               => 'getenv(DB_HOST)',
    'db_port'               => 'getenv(DB_PORT)',
    'db_name'               => 'getenv(DB_NAME)',
    'db_user'               => 'getenv(DB_USER)',
    'db_password'           => 'getenv(DB_PASSWD)',
    'db_table_prefix'       => 'getenv(MAUTIC_TABLE_PREFIX)',
    'secret'                => 'getenv(MAUTIC_SECRET)',
    'default_pagelimit'     => 10,
    'mailer_from_name'      => 'getenv(MAUTIC_EMAIL_FROM_NAME)',
    'mailer_from_email'     => 'getenv(MAUTIC_EMAIL_FROM_EMAIL)',
];
