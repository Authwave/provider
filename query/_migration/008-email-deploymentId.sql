alter table email
    add deploymentId varchar(32) not null after id;

alter table email
    add constraint email_application_deployment_id_fk
        foreign key (deploymentId) references application_deployment (id)
            on update cascade on delete cascade;
