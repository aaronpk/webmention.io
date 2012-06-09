class Link
  include DataMapper::Resource
  property :id, Serial

  property :href, String, :length => 512
  property :verified, Boolean
  property :summary, Text

  belongs_to :page

  property :created_at, DateTime
  property :updated_at, DateTime
end
