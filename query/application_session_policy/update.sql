update ApplicationSessionPolicy
set maximumLifetimeMinutes = :maximumLifetimeMinutes, idleTimeoutMinutes = :idleTimeoutMinutes
where applicationId = :applicationId
