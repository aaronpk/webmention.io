class User
  include DataMapper::Resource
  property :id, Serial

  property :username, String, :length => 255
  property :email, String, :length => 255

  has n, :sites

  property :last_login_date, DateTime
  property :created_at, DateTime
  property :updated_at, DateTime

end
