class Block
  include DataMapper::Resource
  property :id, Serial
  property :created_at, DateTime

  belongs_to :account

  property :domain, String, :length => 256, :index => true
end
