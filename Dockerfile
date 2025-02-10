# Use the official Microsoft SQL Server image
FROM mcr.microsoft.com/mssql/server:latest

# Set environment variables for SQL Server
ENV ACCEPT_EULA=Y
ENV SA_PASSWORD=Password123
ENV MSSQL_PID=Express

# Expose the SQL Server port
EXPOSE 1433