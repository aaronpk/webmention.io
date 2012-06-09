class Page
  include DataMapper::Resource
  property :id, Serial

  property :href, String, :length => 512

  belongs_to :user
  belongs_to :site
  has n, :links

  property :created_at, DateTime
  property :updated_at, DateTime
end
