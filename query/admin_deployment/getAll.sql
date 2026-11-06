select id, title, providerHost, clientHost, clientLoginPath, secret, domainVerified, clientConnected, userLoggedIn
from ApplicationDeployment where applicationId = ? order by title, id
