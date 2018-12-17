Encoding.default_internal = 'UTF-8'
Encoding.default_external = 'UTF-8'
ENV['TZ'] = 'UTC'
require 'rubygems'
require 'bundler/setup'
require 'cgi'
require 'xmlrpc/marshal'
require 'securerandom'
require 'openssl'

Bundler.require :default, ENV['RACK_ENV']
Dir.glob(['lib', 'models', 'helpers'].map! {|d| File.join File.expand_path(File.dirname(__FILE__)), d, '*.rb'}).each {|f| require f}

unless File.exists? './config.yml'
  puts 'Please provide a config.yml file.'
  exit false
end

class ConfigHelper < Hashie::Mash
  def key; self['key'] end
end

SiteConfig = ConfigHelper.new YAML.load_file('config.yml')[ENV['RACK_ENV']] if File.exists?('config.yml')


class Controller < Sinatra::Base
  configure do

    helpers  Sinatra::UserAgentHelpers

    # Set controller names so we can map them in the config.ru file.
    set :controller_names, []
    Dir.glob('controllers/*.rb').each do |file|
      settings.controller_names << File.basename(file, '.rb')
#      require_relative "./#{file}"
    end

    use Rack::Session::Cookie, :key => 'rack.session',
                               :path => '/',
                               :expire_after => 2592000,
                               :secret => SiteConfig.session_secret

    set :root, File.dirname(__FILE__)
    set :raise_errors,    false
    set :protection, :except => [:frame_options, :json_csrf]

    if ENV['RACK_ENV'] == 'development'
      set :show_exceptions, true
      DataMapper::Logger.new(STDOUT, :debug)
    else
      set :show_exceptions, false
    end

    DataMapper.finalize
    DataMapper.setup :default, SiteConfig.database_url
    DataMapper.repository.adapter.execute('SET NAMES utf8mb4')
    DataMapper.repository.adapter.execute('SET SESSION sql_mode = ""')

    set :views, 'views'
    set :erubis,          :escape_html => true
    set :public_folder, File.dirname(__FILE__) + '/public'
  end

  def p; params end
end

Dir.glob(['controllers'].map! {|d| File.join d, '*.rb'}).each do |f|
  require_relative f
end
