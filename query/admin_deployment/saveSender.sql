update ApplicationDeployment
set emailPrefix = :emailPrefix, emailReplyTo = :emailReplyTo, emailFromName = :emailFromName
where id = :deploymentId and applicationId = :applicationId
