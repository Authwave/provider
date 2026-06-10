alter table application
    add productionApplicationDeploymentId varchar(32) null;

alter table application
    add constraint application_production_application_deployment_id_fk
        foreign key (productionApplicationDeploymentId) references application_deployment (id)
            on update cascade on delete set null;
