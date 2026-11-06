select Application.id
from Application
inner join UserAdmin on UserAdmin.applicationId = Application.id
where Application.id = ? and UserAdmin.userId = ?
