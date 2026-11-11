alter table User
    drop foreign key user__applicationDeploymentId__fk;
alter table User
    add constraint user__applicationDeploymentId__fk
        foreign key (applicationDeploymentId) references ApplicationDeployment(id)
        on update cascade on delete restrict;

alter table Email
    drop foreign key email__deploymentId__fk;
alter table Email
    add constraint email__deploymentId__fk
        foreign key (deploymentId) references ApplicationDeployment(id)
        on update cascade on delete restrict;
