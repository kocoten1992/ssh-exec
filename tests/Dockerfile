FROM debian:latest

ENV DEBIAN_FRONTEND=noninteractive

RUN apt update && apt install openssh-server -y

CMD service ssh start && tail -f /dev/null
