select applicationId, maximumLifetimeMinutes, idleTimeoutMinutes
from ApplicationSessionPolicy where applicationId = ?
