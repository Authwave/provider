alter table ApplicationDeployment
    add providerHost varchar(256) not null after secret;

