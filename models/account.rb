class Account
  include DataMapper::Resource
  property :id, Serial

  property :username, String, :length => 255
  property :email, String, :length => 255
  property :xmpp_to, String, :length => 255
  property :xmpp_user, String, :length => 255
  property :xmpp_password, String, :length => 255

  has n, :sites

  property :token, String, :length => 255
  property :zenircbot_uri, String, :length => 255

  property :created_at, DateTime
  property :updated_at, DateTime

end
