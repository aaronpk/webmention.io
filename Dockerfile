FROM ruby:2.5.0

ENV RACK_ENV development

RUN apt-get update -qq && apt-get install -y mysql-client

RUN mkdir /app
COPY . /app
WORKDIR /app
RUN cp config.docker-compose.yml config.yml

RUN bundle install

CMD ["sh", "/app/docker_start.sh"]
