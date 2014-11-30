class Site
  include DataMapper::Resource
  property :id, Serial

  property :domain, String, :length => 255

  belongs_to :account
  has n, :pages
  property :public_access, Boolean, :default => true
  property :irc_channel, String, :length => 255
  property :xmpp_notify, Boolean, :default => false

  property :created_at, DateTime
  property :updated_at, DateTime
end
