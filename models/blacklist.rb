class Blacklist
  include DataMapper::Resource
  property :id, Serial
  property :created_at, DateTime

  belongs_to :site

  property :source, String, :length => 512
end