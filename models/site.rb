class Site
  include DataMapper::Resource
  property :id, Serial

  property :domain, String, :length => 255

  belongs_to :user
  has n, :pages

  property :created_at, DateTime
  property :updated_at, DateTime
end
