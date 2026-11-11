alter table Application
    add productionApplicationDeploymentId varchar(32) null;

alter table Application
    add constraint application_production_application_deployment_id_fk
        foreign key (productionApplicationDeploymentId) references ApplicationDeployment (id)
            on update cascade on delete set null;
