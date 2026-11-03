select
    (select count(*) from User where applicationDeploymentId = ?)
    + (select count(*) from Email where deploymentId = ?) as total
