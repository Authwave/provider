update ApplicationDeployment set domainVerified = 1
where id = ? and applicationId = ? and providerHost = ? and domainVerified = 0
