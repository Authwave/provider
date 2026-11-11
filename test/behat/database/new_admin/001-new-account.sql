-- A global provider administrator can sign in, but has no managed application assignments.
delete from UserAdmin where userId = 'administrator';
