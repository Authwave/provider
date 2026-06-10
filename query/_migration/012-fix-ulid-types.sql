set foreign_key_checks = 0;

alter table application
    drop foreign key application_production_application_deployment_id_fk;

alter table email
    drop foreign key email_application_deployment_id_fk;

alter table user_auth_code
    drop foreign key user_auth_code_ibfk_1;

alter table user
    drop foreign key user_ibfk_1;

alter table application_deployment
    drop foreign key application_deployment_ibfk_1;

alter table application
    modify id char(64) not null;

alter table application_deployment
    modify id char(64) not null,
    modify applicationId char(64) not null;

alter table user
    modify id char(64) not null,
    modify applicationDeploymentId char(64) not null;

alter table user_auth_code
    modify id char(64) not null,
    modify userId char(64) not null;

alter table email
    modify id char(64) not null,
    modify deploymentId char(64) not null;

alter table application
    modify productionApplicationDeploymentId char(64) null;

alter table application_deployment
    add constraint application_deployment__applicationId__fk
        foreign key (applicationId)
        references application(id)
        on update cascade
        on delete cascade;

alter table user
    add constraint user__applicationDeploymentId__fk
        foreign key (applicationDeploymentId)
        references application_deployment(id)
        on update cascade
        on delete cascade;

alter table user_auth_code
    add constraint user_auth_code__userId__fk
        foreign key(userId)
        references user(id)
        on update cascade
        on delete cascade;

alter table email
    add constraint email__deploymentId__fk
        foreign key (deploymentId) references application_deployment (id)
            on update cascade on delete cascade;

alter table application
    add constraint application__productionApplicationDeploymentId__fk
        foreign key (productionApplicationDeploymentId) references application_deployment (id)
            on update cascade on delete set null;

set foreign_key_checks = 1;
