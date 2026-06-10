alter table application_deployment
    add providerHost varchar(256) not null after secret;

