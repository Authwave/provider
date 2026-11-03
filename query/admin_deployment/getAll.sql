select id, title, providerHost, clientHost, clientLoginPath, secret
from ApplicationDeployment where applicationId = ? order by title, id
