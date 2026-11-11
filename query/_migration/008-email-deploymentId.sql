alter table Email
    add deploymentId varchar(32) not null after id;

alter table Email
    add constraint email_application_deployment_id_fk
        foreign key (deploymentId) references ApplicationDeployment (id)
            on update cascade on delete cascade;
