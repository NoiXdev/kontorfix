FROM alpine:3.20
RUN apk add --no-cache git git-daemon
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
EXPOSE 9418
ENTRYPOINT ["/entrypoint.sh"]
